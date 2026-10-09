<?php

namespace Modules\VanSales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Services\StockService;
use Modules\VanSales\Models\VanCustomerBorrowBalance;
use Modules\VanSales\Models\VanReturnBorrowOrder;
use Modules\VanSales\Models\VanReturnBorrowOrderItem;

/**
 * 车销还货单（VanReturnBorrowOrder）
 *
 * 客户归还借出的商品：还货审核通过后商品回到车上库存、减客户借货余额。
 * 与借货单（VanBorrowOrder）成对——借货扣库存+增余额，还货增库存+减余额。
 * 还货不产生资金流水。
 *
 * 状态机：draft → approved(入车上库存+减借货余额，不可逆) / cancelled
 */
class VanReturnBorrowOrderController extends Controller
{
    use ResponseTrait;

    public function __construct(private StockService $stockService) {}

    /** 列表 */
    public function index(Request $request)
    {
        $query = $this->applyFilters(VanReturnBorrowOrder::with(['customer', 'vehicle', 'borrowOrder']), $request);

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        return $this->paginated($query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page));
    }

    /** 导出 */
    public function export(Request $request)
    {
        $list = $this->applyFilters(VanReturnBorrowOrder::with(['customer', 'vehicle', 'borrowOrder']), $request)
            ->orderByDesc('id')->get();

        $csv = "\u{FEFF}车销还货单列表\n\n";
        $csv .= "还货日期,还货单号,业务员,客户,车牌号,关联借货单,商品总数,金额,状态\n";

        foreach ($list as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $r->return_date?->format('Y-m-d') ?? '',
                $this->csvCell($r->return_no),
                $this->csvCell($r->salesman_name ?? ''),
                $this->csvCell($r->customer_name ?? $r->customer?->name ?? ''),
                $this->csvCell($r->vehicle?->plate_no ?? ''),
                $this->csvCell($r->borrowOrder?->borrow_no ?? ''),
                $r->total_qty,
                number_format((float) $r->total_amount, 2, '.', ''),
                $this->statusLabel($r->status)
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="van_return_borrow_orders.csv"',
        ]);
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('return_no')) {
            $query->where('return_no', 'like', '%'.trim((string) $request->input('return_no')).'%');
        }
        if ($request->filled('salesman_id')) {
            $query->where('salesman_id', (int) $request->input('salesman_id'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->input('customer_id'));
        }
        if ($request->filled('customer_name')) {
            $query->where('customer_name', 'like', '%'.trim((string) $request->input('customer_name')).'%');
        }
        if ($request->filled('borrow_order_id')) {
            $query->where('borrow_order_id', (int) $request->input('borrow_order_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('start_date')) {
            $query->whereDate('return_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('return_date', '<=', $request->input('end_date'));
        }

        return $query;
    }

    private function csvCell(?string $value): string
    {
        $value = (string) $value;
        if (preg_match('/[",\n\r]/', $value)) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => '草稿',
            'approved' => '已还入',
            'cancelled' => '已取消',
            default => $status,
        };
    }

    /** 详情 */
    public function show($id)
    {
        $order = VanReturnBorrowOrder::with(['items.product', 'customer', 'vehicle', 'borrowOrder'])->find($id);
        if (! $order) {
            return $this->notFound('还货单不存在');
        }

        return $this->success($order);
    }

    /** 创建草稿 */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'vehicle_warehouse_id' => 'required|exists:warehouses,id',
            'borrow_order_id' => 'nullable|exists:van_borrow_orders,id',
            'return_date' => 'nullable|date',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.return_qty' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.remark' => 'nullable|string|max:255',
        ]);

        [$adminId, $adminName] = $this->currentAdmin();

        return DB::transaction(function () use ($validated, $adminId, $adminName) {
            $vwId = (int) $validated['vehicle_warehouse_id'];
            $customerId = (int) $validated['customer_id'];

            $productIds = collect($validated['items'])->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
            $productMap = DB::table('products')->whereIn('id', $productIds)->get()->keyBy('id');

            $totalQty = 0;
            $totalAmount = 0.0;
            $itemRows = [];
            $sort = 0;

            foreach ($validated['items'] as $item) {
                $productId = (int) $item['product_id'];
                $product = $productMap->get($productId);
                if (! $product) {
                    continue;
                }
                $returnQty = (int) $item['return_qty'];
                $unitPrice = (float) ($item['unit_price'] ?? 0);
                $amount = round($returnQty * $unitPrice, 2);
                $totalQty += $returnQty;
                $totalAmount += $amount;

                $itemRows[] = [
                    'product_id' => $productId,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'spec' => $product->spec,
                    'unit' => $product->price_unit_small,
                    'return_qty' => $returnQty,
                    'unit_price' => $unitPrice,
                    'amount' => $amount,
                    'remark' => $item['remark'] ?? null,
                    'sort' => $sort,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $sort++;
            }

            if (empty($itemRows)) {
                return $this->error('没有有效的商品明细', 422);
            }

            $order = VanReturnBorrowOrder::create([
                'return_no' => $this->generateNo('VHT', 'van_return_borrow_orders', 'return_no'),
                'salesman_id' => $adminId,
                'salesman_name' => $adminName,
                'customer_id' => $customerId,
                'customer_name' => DB::table('customers')->where('id', $customerId)->value('name'),
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'vehicle_warehouse_id' => $vwId,
                'borrow_order_id' => $validated['borrow_order_id'] ?? null,
                'return_date' => $validated['return_date'] ?? now()->toDateString(),
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
                'status' => VanReturnBorrowOrder::STATUS_DRAFT,
                'created_by' => $adminId,
                'creator_name' => $adminName,
                'remark' => $validated['remark'] ?? null,
            ]);

            foreach ($itemRows as &$row) {
                $row['order_id'] = $order->id;
            }
            unset($row);
            VanReturnBorrowOrderItem::insert($itemRows);

            return $this->created($order->load('items', 'customer', 'vehicle', 'borrowOrder'), '草稿已保存');
        });
    }

    /** 更新草稿 */
    public function update(Request $request, $id)
    {
        $order = VanReturnBorrowOrder::find($id);
        if (! $order) {
            return $this->notFound('还货单不存在');
        }
        if ($order->status !== VanReturnBorrowOrder::STATUS_DRAFT) {
            return $this->error('当前状态不能修改', 422);
        }

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'borrow_order_id' => 'nullable|exists:van_borrow_orders,id',
            'return_date' => 'nullable|date',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.return_qty' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.remark' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($order, $validated) {
            $customerId = (int) $validated['customer_id'];
            $productIds = collect($validated['items'])->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
            $productMap = DB::table('products')->whereIn('id', $productIds)->get()->keyBy('id');

            $totalQty = 0;
            $totalAmount = 0.0;
            $itemRows = [];
            $sort = 0;

            foreach ($validated['items'] as $item) {
                $productId = (int) $item['product_id'];
                $product = $productMap->get($productId);
                if (! $product) {
                    continue;
                }
                $returnQty = (int) $item['return_qty'];
                $unitPrice = (float) ($item['unit_price'] ?? 0);
                $amount = round($returnQty * $unitPrice, 2);
                $totalQty += $returnQty;
                $totalAmount += $amount;

                $itemRows[] = [
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'spec' => $product->spec,
                    'unit' => $product->price_unit_small,
                    'return_qty' => $returnQty,
                    'unit_price' => $unitPrice,
                    'amount' => $amount,
                    'remark' => $item['remark'] ?? null,
                    'sort' => $sort,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $sort++;
            }

            if (empty($itemRows)) {
                return $this->error('没有有效的商品明细', 422);
            }

            $order->items()->delete();
            VanReturnBorrowOrderItem::insert($itemRows);
            $order->update([
                'customer_id' => $customerId,
                'customer_name' => DB::table('customers')->where('id', $customerId)->value('name'),
                'borrow_order_id' => $validated['borrow_order_id'] ?? $order->borrow_order_id,
                'return_date' => $validated['return_date'] ?? $order->return_date?->toDateString() ?? now()->toDateString(),
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
                'remark' => array_key_exists('remark', $validated) ? $validated['remark'] : $order->remark,
            ]);

            return $this->success($order->load('items', 'customer', 'vehicle', 'borrowOrder'), '草稿已更新');
        });
    }

    /** 删除（仅 draft） */
    public function destroy($id)
    {
        $order = VanReturnBorrowOrder::find($id);
        if (! $order) {
            return $this->notFound('还货单不存在');
        }
        if ($order->status !== VanReturnBorrowOrder::STATUS_DRAFT) {
            return $this->error('当前状态不能删除', 422);
        }

        $order->items()->delete();
        $order->delete();

        return $this->success(null, '已删除');
    }

    /**
     * 审核还货（draft → approved）：事务内 还货入车上库存 + 减客户借货余额。
     * 还货不产生资金流水。
     */
    public function approve($id)
    {
        $order = VanReturnBorrowOrder::with('items')->find($id);
        if (! $order) {
            return $this->notFound('还货单不存在');
        }
        if ($order->status === VanReturnBorrowOrder::STATUS_APPROVED) {
            return $this->success($order, '已审核，无需重复操作');
        }
        if ($order->status !== VanReturnBorrowOrder::STATUS_DRAFT) {
            return $this->error('只有草稿状态的还货单才能审核', 422);
        }

        [$adminId, $adminName] = $this->currentAdmin();

        DB::transaction(function () use ($order, $adminId, $adminName) {
            foreach ($order->items as $item) {
                $returnQty = (int) $item->return_qty;
                if ($returnQty <= 0) {
                    continue;
                }
                $this->stockService->stockIn(
                    (int) $item->product_id,
                    (int) $order->vehicle_warehouse_id,
                    $returnQty,
                    null,
                    (int) $order->id,
                    'VanReturnBorrowOrder'
                );

                // 减客户借货余额（不超过现有余额）
                $this->decreaseBorrowBalance((int) $order->customer_id, (int) $item->product_id, $returnQty);
            }

            $order->update(['status' => VanReturnBorrowOrder::STATUS_APPROVED, 'approved_at' => now()]);
            $this->writeOperationLog($order, $adminId, $adminName, 'approve', '还货审核通过', VanReturnBorrowOrder::STATUS_DRAFT, VanReturnBorrowOrder::STATUS_APPROVED, '还入数量'.(int) $order->total_qty.'，金额¥'.number_format((float) $order->total_amount, 2));
        });

        return $this->success($order->load('items', 'customer', 'vehicle', 'borrowOrder'), '还货已审核，商品已回到车上库存');
    }

    /** 取消（仅 draft） */
    public function cancel($id)
    {
        $order = VanReturnBorrowOrder::find($id);
        if (! $order) {
            return $this->notFound('还货单不存在');
        }
        if ($order->status !== VanReturnBorrowOrder::STATUS_DRAFT) {
            return $this->error('当前状态不能取消', 422);
        }

        $order->update(['status' => VanReturnBorrowOrder::STATUS_CANCELLED]);

        return $this->success($order, '已取消');
    }

    private function decreaseBorrowBalance(int $customerId, int $productId, int $qty): void
    {
        $balance = VanCustomerBorrowBalance::where('customer_id', $customerId)
            ->where('product_id', $productId)->lockForUpdate()->first();
        if (! $balance) {
            return;
        }
        $newQty = (int) $balance->qty - $qty;
        $balance->update(['qty' => $newQty, 'updated_at' => now()]);
    }

    private function writeOperationLog(VanReturnBorrowOrder $order, ?int $adminId, string $adminName, string $action, string $actionLabel, ?string $fromStatus, ?string $toStatus, ?string $detail = null): void
    {
        DB::table('order_operation_logs')->insert([
            'order_id' => $order->id,
            'order_no' => $order->return_no,
            'order_type' => 'van_return_borrow_order',
            'user_id' => $adminId,
            'user_name' => $adminName,
            'operator_id' => $adminId,
            'operator_name' => $adminName,
            'action' => $action,
            'action_label' => $actionLabel,
            'detail' => $detail,
            'remark' => null,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function currentAdmin(): array
    {
        $admin = auth('admin')->user();

        return [$admin?->id, $admin?->real_name ?? $admin?->username ?? '管理员'];
    }

    private function generateNo(string $prefix, string $table, string $column): string
    {
        $like = $prefix.date('Ymd').'%';
        $last = DB::table($table)->where($column, 'like', $like)->orderByDesc($column)->value($column);
        $seq = $last ? intval(substr($last, -6)) + 1 : 1;

        return $prefix.date('Ymd').str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
