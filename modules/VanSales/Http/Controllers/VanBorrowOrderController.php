<?php

namespace Modules\VanSales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Exceptions\StockRuleException;
use Modules\Stock\Services\StockService;
use Modules\VanSales\Models\VanBorrowOrder;
use Modules\VanSales\Models\VanBorrowOrderItem;
use Modules\VanSales\Models\VanCustomerBorrowBalance;

/**
 * 车销借货单（VanBorrowOrder）
 *
 * 客户借用商品（不结算货款）：借货审核通过后扣车上库存、增客户借货余额。
 * 与欠款（customers.balance）解耦——借货余额按商品维度记在 van_customer_borrow_balances。
 *
 * 状态机：draft → approved(扣库存+记借货余额，不可逆) / cancelled
 */
class VanBorrowOrderController extends Controller
{
    use ResponseTrait;

    public function __construct(private StockService $stockService) {}

    /** 指定车上仓的商品（新增借货单时加载明细用） */
    public function vehicleProducts(Request $request)
    {
        $request->validate(['vehicle_warehouse_id' => 'required|exists:warehouses,id']);
        $vwId = (int) $request->input('vehicle_warehouse_id');

        $products = DB::table('stocks as s')
            ->join('products as p', 's.product_id', '=', 'p.id')
            ->where('s.warehouse_id', $vwId)
            ->where('s.quantity', '>', 0)
            ->where('p.is_active', 1)
            ->orderBy('p.name')
            ->get([
                's.product_id', 'p.code as product_code', 'p.name as product_name',
                'p.spec', 'p.price_unit_small as unit',
                's.quantity as stock_qty', 's.cost_price',
                'p.price_small', 'p.price_large',
            ])
            ->map(function ($r) {
                $r->stock_qty = (int) $r->stock_qty;
                $r->cost_price = (float) $r->cost_price;
                $r->price_small = (float) $r->price_small;

                return $r;
            });

        return $this->success(['list' => $products, 'total' => $products->count()]);
    }

    /** 列表 */
    public function index(Request $request)
    {
        $query = $this->applyFilters(VanBorrowOrder::with(['customer', 'vehicle']), $request);

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        return $this->paginated($query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page));
    }

    /** 借货余额（按客户+商品维度） */
    public function balances(Request $request)
    {
        $request->validate(['customer_id' => 'nullable|integer']);
        $query = DB::table('van_customer_borrow_balances as b')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->where('b.qty', '>', 0);
        if ($request->filled('customer_id')) {
            $query->where('b.customer_id', (int) $request->input('customer_id'));
        }

        $list = $query->orderBy('b.customer_id')->orderBy('b.id')
            ->get(['b.customer_id', 'b.product_id', 'p.code as product_code', 'p.name as product_name', 'b.qty'])
            ->map(fn ($r) => $r);

        return $this->success(['list' => $list, 'total' => $list->count()]);
    }

    /** 导出 */
    public function export(Request $request)
    {
        $list = $this->applyFilters(VanBorrowOrder::with(['customer', 'vehicle']), $request)
            ->orderByDesc('id')->get();

        $csv = "\u{FEFF}车销借货单列表\n\n";
        $csv .= "借货日期,借货单号,业务员,客户,车牌号,商品总数,借货金额,应还日期,状态\n";

        foreach ($list as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $r->borrow_date?->format('Y-m-d') ?? '',
                $this->csvCell($r->borrow_no),
                $this->csvCell($r->salesman_name ?? ''),
                $this->csvCell($r->customer_name ?? $r->customer?->name ?? ''),
                $this->csvCell($r->vehicle?->plate_no ?? ''),
                $r->total_qty,
                number_format((float) $r->total_amount, 2, '.', ''),
                $r->due_date?->format('Y-m-d') ?? '',
                $this->statusLabel($r->status)
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="van_borrow_orders.csv"',
        ]);
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('borrow_no')) {
            $query->where('borrow_no', 'like', '%'.trim((string) $request->input('borrow_no')).'%');
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
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('start_date')) {
            $query->whereDate('borrow_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('borrow_date', '<=', $request->input('end_date'));
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
            'approved' => '已借出',
            'cancelled' => '已取消',
            default => $status,
        };
    }

    /** 详情 */
    public function show($id)
    {
        $order = VanBorrowOrder::with(['items.product', 'customer', 'vehicle'])->find($id);
        if (! $order) {
            return $this->notFound('借货单不存在');
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
            'borrow_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.borrow_qty' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.remark' => 'nullable|string|max:255',
        ]);

        [$adminId, $adminName] = $this->currentAdmin();

        return DB::transaction(function () use ($validated, $adminId, $adminName) {
            $vwId = (int) $validated['vehicle_warehouse_id'];
            $customerId = (int) $validated['customer_id'];

            $stockMap = DB::table('stocks')->where('warehouse_id', $vwId)->get()->keyBy('product_id');
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
                $borrowQty = (int) $item['borrow_qty'];
                $stockQty = (int) ($stockMap->get($productId)?->quantity ?? 0);
                if ($borrowQty > $stockQty) {
                    return $this->error("商品【{$product->name}】借货数量{$borrowQty}超过车上库存{$stockQty}", 422);
                }
                $unitPrice = (float) ($item['unit_price'] ?? $product->price_small ?? 0);
                $amount = round($borrowQty * $unitPrice, 2);
                $totalQty += $borrowQty;
                $totalAmount += $amount;

                $itemRows[] = [
                    'product_id' => $productId,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'spec' => $product->spec,
                    'unit' => $product->price_unit_small,
                    'borrow_qty' => $borrowQty,
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

            $order = VanBorrowOrder::create([
                'borrow_no' => $this->generateNo('VJT', 'van_borrow_orders', 'borrow_no'),
                'salesman_id' => $adminId,
                'salesman_name' => $adminName,
                'customer_id' => $customerId,
                'customer_name' => DB::table('customers')->where('id', $customerId)->value('name'),
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'vehicle_warehouse_id' => $vwId,
                'borrow_date' => $validated['borrow_date'] ?? now()->toDateString(),
                'due_date' => $validated['due_date'] ?? null,
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
                'status' => VanBorrowOrder::STATUS_DRAFT,
                'created_by' => $adminId,
                'creator_name' => $adminName,
                'remark' => $validated['remark'] ?? null,
            ]);

            foreach ($itemRows as &$row) {
                $row['order_id'] = $order->id;
            }
            unset($row);
            VanBorrowOrderItem::insert($itemRows);

            return $this->created($order->load('items', 'customer', 'vehicle'), '草稿已保存');
        });
    }

    /** 更新草稿 */
    public function update(Request $request, $id)
    {
        $order = VanBorrowOrder::find($id);
        if (! $order) {
            return $this->notFound('借货单不存在');
        }
        if ($order->status !== VanBorrowOrder::STATUS_DRAFT) {
            return $this->error('当前状态不能修改', 422);
        }

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'borrow_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.borrow_qty' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.remark' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($order, $validated) {
            $vwId = (int) $order->vehicle_warehouse_id;
            $customerId = (int) $validated['customer_id'];
            $stockMap = DB::table('stocks')->where('warehouse_id', $vwId)->get()->keyBy('product_id');
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
                $borrowQty = (int) $item['borrow_qty'];
                $stockQty = (int) ($stockMap->get($productId)?->quantity ?? 0);
                if ($borrowQty > $stockQty) {
                    return $this->error("商品【{$product->name}】借货数量超过车上库存", 422);
                }
                $unitPrice = (float) ($item['unit_price'] ?? $product->price_small ?? 0);
                $amount = round($borrowQty * $unitPrice, 2);
                $totalQty += $borrowQty;
                $totalAmount += $amount;

                $itemRows[] = [
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'spec' => $product->spec,
                    'unit' => $product->price_unit_small,
                    'borrow_qty' => $borrowQty,
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
            VanBorrowOrderItem::insert($itemRows);
            $order->update([
                'customer_id' => $customerId,
                'customer_name' => DB::table('customers')->where('id', $customerId)->value('name'),
                'borrow_date' => $validated['borrow_date'] ?? $order->borrow_date?->toDateString() ?? now()->toDateString(),
                'due_date' => array_key_exists('due_date', $validated) ? $validated['due_date'] : $order->due_date,
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
                'remark' => array_key_exists('remark', $validated) ? $validated['remark'] : $order->remark,
            ]);

            return $this->success($order->load('items', 'customer', 'vehicle'), '草稿已更新');
        });
    }

    /** 删除（仅 draft） */
    public function destroy($id)
    {
        $order = VanBorrowOrder::find($id);
        if (! $order) {
            return $this->notFound('借货单不存在');
        }
        if ($order->status !== VanBorrowOrder::STATUS_DRAFT) {
            return $this->error('当前状态不能删除', 422);
        }

        $order->items()->delete();
        $order->delete();

        return $this->success(null, '已删除');
    }

    /**
     * 审核借货（draft → approved）：事务内 扣车上库存 + 增客户借货余额。
     * 借货不产生资金流水，只记商品借出数量。
     */
    public function approve($id)
    {
        $order = VanBorrowOrder::with('items')->find($id);
        if (! $order) {
            return $this->notFound('借货单不存在');
        }
        if ($order->status === VanBorrowOrder::STATUS_APPROVED) {
            return $this->success($order, '已审核，无需重复操作');
        }
        if ($order->status !== VanBorrowOrder::STATUS_DRAFT) {
            return $this->error('只有草稿状态的借货单才能审核', 422);
        }

        [$adminId, $adminName] = $this->currentAdmin();

        try {
            DB::transaction(function () use ($order, $adminId, $adminName) {
                foreach ($order->items as $item) {
                    $borrowQty = (int) $item->borrow_qty;
                    if ($borrowQty <= 0) {
                        continue;
                    }
                    $this->stockService->stockOut(
                        (int) $item->product_id,
                        (int) $order->vehicle_warehouse_id,
                        $borrowQty,
                        (int) $order->id,
                        'VanBorrowOrder',
                        '车销借货出库'
                    );

                    // 增客户借货余额
                    $this->increaseBorrowBalance((int) $order->customer_id, (int) $item->product_id, $borrowQty);
                }

                $order->update(['status' => VanBorrowOrder::STATUS_APPROVED, 'approved_at' => now()]);
                $this->writeOperationLog($order, $adminId, $adminName, 'approve', '借货审核通过', VanBorrowOrder::STATUS_DRAFT, VanBorrowOrder::STATUS_APPROVED, '借出数量'.(int) $order->total_qty.'，金额¥'.number_format((float) $order->total_amount, 2));
            });
        } catch (StockRuleException $e) {
            return $this->error('审核借货失败：'.$e->getMessage(), 422);
        }

        return $this->success($order->load('items', 'customer', 'vehicle'), '借货已审核，车上库存已扣减');
    }

    /** 取消（仅 draft） */
    public function cancel($id)
    {
        $order = VanBorrowOrder::find($id);
        if (! $order) {
            return $this->notFound('借货单不存在');
        }
        if ($order->status !== VanBorrowOrder::STATUS_DRAFT) {
            return $this->error('当前状态不能取消', 422);
        }

        $order->update(['status' => VanBorrowOrder::STATUS_CANCELLED]);

        return $this->success($order, '已取消');
    }

    private function increaseBorrowBalance(int $customerId, int $productId, int $qty): void
    {
        $balance = VanCustomerBorrowBalance::where('customer_id', $customerId)
            ->where('product_id', $productId)->lockForUpdate()->first();
        if ($balance) {
            $balance->update(['qty' => (int) $balance->qty + $qty, 'updated_at' => now()]);
        } else {
            VanCustomerBorrowBalance::create([
                'customer_id' => $customerId,
                'product_id' => $productId,
                'qty' => $qty,
            ]);
        }
    }

    private function writeOperationLog(VanBorrowOrder $order, ?int $adminId, string $adminName, string $action, string $actionLabel, ?string $fromStatus, ?string $toStatus, ?string $detail = null): void
    {
        DB::table('order_operation_logs')->insert([
            'order_id' => $order->id,
            'order_no' => $order->borrow_no,
            'order_type' => 'van_borrow_order',
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
