<?php

namespace Modules\BorrowReturn\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\BorrowReturn\Models\BorrowOrder;
use Modules\BorrowReturn\Models\BorrowOrderItem;
use Modules\BorrowReturn\Models\CustomerBorrowBalance;
use Modules\Order\Models\Customer;
use Modules\Order\Models\SalesOrder;
use Modules\Order\Models\SalesOrderItem;
use Modules\Stock\Exceptions\StockRuleException;
use Modules\Stock\Services\StockService;

/**
 * 借货单（BorrowOrder）
 *
 * 客户从仓库借走商品，后续归还或转销售。
 * 状态机：draft(保存草稿) → unreturned(确认借货，扣库存+记借货余额)
 *        → partial(部分还) / cleared(已还清) / converted(已转销售) / cancelled(已取消)
 * 草稿与未还在前端统一展示为「未还」。
 */
class BorrowOrderController extends Controller
{
    use ResponseTrait;

    public function __construct(private StockService $stockService) {}

    /** 指定仓库的可借商品（新增借货单时加载明细用） */
    public function warehouseProducts(Request $request)
    {
        $request->validate(['warehouse_id' => 'required|exists:warehouses,id']);
        $whId = (int) $request->input('warehouse_id');

        $products = DB::table('stocks as s')
            ->join('products as p', 's.product_id', '=', 'p.id')
            ->where('s.warehouse_id', $whId)
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

    public function index(Request $request)
    {
        $query = $this->applyFilters(BorrowOrder::with(['customer', 'warehouse']), $request);

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        return $this->paginated($query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page));
    }

    public function export(Request $request)
    {
        $list = $this->applyFilters(BorrowOrder::with(['customer', 'warehouse']), $request)
            ->orderByDesc('id')->get();

        $csv = "\u{FEFF}借货单列表\n\n";
        $csv .= "借货日期,借货单号,客户,联系人,电话,仓库,业务员,应还日期,商品种类,借货总数,借货金额,已还数量,状态\n";

        foreach ($list as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $r->borrow_date?->format('Y-m-d') ?? '',
                $this->csvCell($r->borrow_no),
                $this->csvCell($r->customer_name ?? ''),
                $this->csvCell($r->contact ?? ''),
                $this->csvCell($r->contact_phone ?? ''),
                $this->csvCell($r->warehouse_name ?? ''),
                $this->csvCell($r->salesman_name ?? ''),
                $r->due_date?->format('Y-m-d') ?? '',
                $r->total_kinds,
                $r->total_qty,
                number_format((float) $r->total_amount, 2, '.', ''),
                $r->returned_qty,
                $this->statusLabel($r->status)
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="borrow_orders.csv"',
        ]);
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('borrow_no')) {
            $query->where('borrow_no', 'like', '%'.trim((string) $request->input('borrow_no')).'%');
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->input('customer_id'));
        }
        if ($request->filled('customer_name')) {
            $query->where('customer_name', 'like', '%'.trim((string) $request->input('customer_name')).'%');
        }
        if ($request->filled('salesman_id')) {
            $query->where('salesman_id', (int) $request->input('salesman_id'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', (int) $request->input('warehouse_id'));
        }
        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status === BorrowOrder::STATUS_UNRETURNED) {
                $query->whereIn('status', [BorrowOrder::STATUS_DRAFT, BorrowOrder::STATUS_UNRETURNED]);
            } else {
                $query->where('status', $status);
            }
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
        return BorrowOrder::statusLabel($status);
    }

    public function show($id)
    {
        $order = BorrowOrder::with(['items.product', 'customer', 'warehouse'])->find($id);
        if (! $order) {
            return $this->notFound('借货单不存在');
        }

        return $this->success($order);
    }

    /** 创建草稿（保存草稿） */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'contact' => 'nullable|string|max:60',
            'contact_phone' => 'nullable|string|max:40',
            'borrow_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'borrow_reason' => 'nullable|string|max:40',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.borrow_qty' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        [$adminId, $adminName] = $this->currentAdmin();

        try {
            return DB::transaction(function () use ($validated, $adminId, $adminName) {
            $whId = (int) $validated['warehouse_id'];
            $customerId = (int) $validated['customer_id'];

            $stockMap = DB::table('stocks')->where('warehouse_id', $whId)->get()->keyBy('product_id');
            $productIds = collect($validated['items'])->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
            $productMap = DB::table('products')->whereIn('id', $productIds)->get()->keyBy('id');

            $itemRows = $this->buildItemRows($validated['items'], $productMap, $stockMap, true);

            $order = BorrowOrder::create([                'borrow_no' => $this->generateNo('JH', 'borrow_orders', 'borrow_no'),
                'customer_id' => $customerId,
                'customer_name' => DB::table('customers')->where('id', $customerId)->value('name'),
                'contact' => $validated['contact'] ?? null,
                'contact_phone' => $validated['contact_phone'] ?? null,
                'warehouse_id' => $whId,
                'warehouse_name' => DB::table('warehouses')->where('id', $whId)->value('name'),
                'salesman_id' => $adminId,
                'salesman_name' => $adminName,
                'borrow_date' => $validated['borrow_date'] ?? now()->toDateString(),
                'due_date' => $validated['due_date'] ?? null,
                'borrow_reason' => $validated['borrow_reason'] ?? null,
                'total_kinds' => count($itemRows),
                'total_qty' => (int) collect($itemRows)->sum('borrow_qty'),
                'total_amount' => round((float) collect($itemRows)->sum('amount'), 2),
                'returned_qty' => 0,
                'returned_amount' => 0,
                'status' => BorrowOrder::STATUS_DRAFT,
                'created_by' => $adminId,
                'creator_name' => $adminName,
                'remark' => $validated['remark'] ?? null,
            ]);

            foreach ($itemRows as &$row) {
                $row['order_id'] = $order->id;
            }
            unset($row);
            BorrowOrderItem::insert($itemRows);

            return $this->created($order->load('items', 'customer', 'warehouse'), '草稿已保存');
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** 更新草稿 */
    public function update(Request $request, $id)
    {
        $order = BorrowOrder::find($id);
        if (! $order) {
            return $this->notFound('借货单不存在');
        }
        if ($order->status !== BorrowOrder::STATUS_DRAFT) {
            return $this->error('只有草稿状态的借货单才能修改', 422);
        }

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'contact' => 'nullable|string|max:60',
            'contact_phone' => 'nullable|string|max:40',
            'borrow_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'borrow_reason' => 'nullable|string|max:40',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.borrow_qty' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        try {
            return DB::transaction(function () use ($order, $validated) {
            $whId = (int) $validated['warehouse_id'];
            $customerId = (int) $validated['customer_id'];
            $stockMap = DB::table('stocks')->where('warehouse_id', $whId)->get()->keyBy('product_id');
            $productIds = collect($validated['items'])->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
            $productMap = DB::table('products')->whereIn('id', $productIds)->get()->keyBy('id');

            $itemRows = $this->buildItemRows($validated['items'], $productMap, $stockMap, true);

            $order->items()->delete();
            foreach ($itemRows as &$row) {
                $row['order_id'] = $order->id;
            }
            unset($row);
            BorrowOrderItem::insert($itemRows);

            $order->update([
                'customer_id' => $customerId,
                'customer_name' => DB::table('customers')->where('id', $customerId)->value('name'),
                'contact' => $validated['contact'] ?? null,
                'contact_phone' => $validated['contact_phone'] ?? null,
                'warehouse_id' => $whId,
                'warehouse_name' => DB::table('warehouses')->where('id', $whId)->value('name'),
                'borrow_date' => $validated['borrow_date'] ?? $order->borrow_date?->toDateString() ?? now()->toDateString(),
                'due_date' => array_key_exists('due_date', $validated) ? $validated['due_date'] : $order->due_date,
                'borrow_reason' => $validated['borrow_reason'] ?? null,
                'total_kinds' => count($itemRows),
                'total_qty' => (int) collect($itemRows)->sum('borrow_qty'),
                'total_amount' => round((float) collect($itemRows)->sum('amount'), 2),
                'remark' => array_key_exists('remark', $validated) ? $validated['remark'] : $order->remark,
            ]);

            return $this->success($order->load('items', 'customer', 'warehouse'), '草稿已更新');
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function destroy($id)
    {
        $order = BorrowOrder::find($id);
        if (! $order) {
            return $this->notFound('借货单不存在');
        }
        if ($order->status !== BorrowOrder::STATUS_DRAFT) {
            return $this->error('当前状态不能删除', 422);
        }

        $order->items()->delete();
        $order->delete();

        return $this->success(null, '已删除');
    }

    /** 确认借货（draft → unreturned）：扣库存 + 增客户借货余额 */
    public function confirm($id)
    {
        $order = BorrowOrder::with('items')->find($id);
        if (! $order) {
            return $this->notFound('借货单不存在');
        }
        if ($order->status === BorrowOrder::STATUS_UNRETURNED) {
            return $this->success($order, '已确认，无需重复操作');
        }
        if ($order->status !== BorrowOrder::STATUS_DRAFT) {
            return $this->error('只有草稿状态的借货单才能确认', 422);
        }

        [$adminId, $adminName] = $this->currentAdmin();

        try {
            DB::transaction(function () use ($order, $adminId, $adminName) {
                foreach ($order->items as $item) {
                    $qty = (int) $item->borrow_qty;
                    if ($qty <= 0) {
                        continue;
                    }
                    $this->stockService->stockOut(
                        (int) $item->product_id,
                        (int) $order->warehouse_id,
                        $qty,
                        (int) $order->id,
                        'BorrowOrder',
                        '借货出库'
                    );
                    $this->increaseBorrowBalance((int) $order->customer_id, (int) $item->product_id, $qty);
                }

                $order->update(['status' => BorrowOrder::STATUS_UNRETURNED, 'approved_at' => now()]);
                $this->writeOperationLog($order, $adminId, $adminName, 'confirm', '确认借货', BorrowOrder::STATUS_DRAFT, BorrowOrder::STATUS_UNRETURNED, '借出数量'.(int) $order->total_qty.'，金额¥'.number_format((float) $order->total_amount, 2));
            });
        } catch (StockRuleException $e) {
            return $this->error('确认借货失败：'.$e->getMessage(), 422);
        }

        return $this->success($order->load('items', 'customer', 'warehouse'), '已确认借货，库存已扣减');
    }

    /** 取消借货（仅未还）：恢复库存 + 清零借货余额 */
    public function cancel($id, Request $request)
    {
        $order = BorrowOrder::with('items')->find($id);
        if (! $order) {
            return $this->notFound('借货单不存在');
        }
        if ($order->status !== BorrowOrder::STATUS_UNRETURNED) {
            return $this->error('只有「未还」状态的借货单才能取消', 422);
        }

        $request->validate(['cancel_reason' => 'required|string|max:500']);
        [$adminId, $adminName] = $this->currentAdmin();

        try {
            DB::transaction(function () use ($order, $request, $adminId, $adminName) {
                foreach ($order->items as $item) {
                    $qty = (int) $item->borrow_qty;
                    if ($qty <= 0) {
                        continue;
                    }
                    $this->stockService->stockIn(
                        (int) $item->product_id,
                        (int) $order->warehouse_id,
                        $qty,
                        null,
                        (int) $order->id,
                        'BorrowOrderCancel'
                    );
                    $this->decreaseBorrowBalance((int) $order->customer_id, (int) $item->product_id, $qty);
                }

                $order->update([
                    'status' => BorrowOrder::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancel_reason' => $request->input('cancel_reason'),
                ]);
                $this->writeOperationLog($order, $adminId, $adminName, 'cancel', '取消借货', BorrowOrder::STATUS_UNRETURNED, BorrowOrder::STATUS_CANCELLED, $request->input('cancel_reason'));
            });
        } catch (StockRuleException $e) {
            return $this->error('取消借货失败：'.$e->getMessage(), 422);
        }

        return $this->success($order, '借货单已取消，库存已恢复');
    }

    /**
     * 转销售（仅未还）：生成销售单，金额=借货金额，客户应收账款增加，借货余额清零。
     * 借货时库存已扣减，转销售不再重复扣库存。
     */
    public function convert($id)
    {
        $order = BorrowOrder::with('items')->find($id);
        if (! $order) {
            return $this->notFound('借货单不存在');
        }
        if ($order->status !== BorrowOrder::STATUS_UNRETURNED) {
            return $this->error('只有「未还」状态的借货单才能转销售', 422);
        }
        if ((int) $order->returned_qty > 0) {
            return $this->error('已有还货记录，不能转销售', 422);
        }

        [$adminId, $adminName] = $this->currentAdmin();

        DB::transaction(function () use ($order, $adminId, $adminName) {
            $salesOrder = SalesOrder::create([
                'order_no' => $this->generateNo('XS', 'sales_orders', 'order_no'),
                'order_type' => 'borrow_convert',
                'customer_id' => $order->customer_id,
                'warehouse_id' => $order->warehouse_id,
                'order_date' => now()->toDateString(),
                'total_qty' => $order->total_qty,
                'total_amount' => $order->total_amount,
                'paid_amount' => 0,
                'status' => 'approved',
                'salesman_id' => $order->salesman_id,
                'salesman_name' => $order->salesman_name,
                'created_by' => $adminId,
                'remark' => '借货转销售-'.$order->borrow_no,
                'payment_status' => 'unpaid',
            ]);

            $itemRows = [];
            foreach ($order->items as $item) {
                $itemRows[] = [
                    'sales_order_id' => $salesOrder->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->borrow_qty,
                    'actual_qty' => $item->borrow_qty,
                    'price' => $item->unit_price,
                    'amount' => $item->amount,
                    'remark' => $item->remark,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            SalesOrderItem::insert($itemRows);

            // 客户应收账款增加
            DB::table('customers')->where('id', $order->customer_id)->increment('balance', (float) $order->total_amount);

            // 借货余额清零
            foreach ($order->items as $item) {
                $this->decreaseBorrowBalance((int) $order->customer_id, (int) $item->product_id, (int) $item->borrow_qty);
            }

            $order->update(['status' => BorrowOrder::STATUS_CONVERTED, 'converted_at' => now()]);
            $this->writeOperationLog($order, $adminId, $adminName, 'convert', '转销售', BorrowOrder::STATUS_UNRETURNED, BorrowOrder::STATUS_CONVERTED, '生成销售单'.($salesOrder->order_no ?? '').'，金额¥'.number_format((float) $order->total_amount, 2));
        });

        return $this->success($order->load('items', 'customer', 'warehouse'), '已转销售，应收账款已增加');
    }

    private function buildItemRows(array $items, $productMap, $stockMap, bool $checkStock): array
    {
        $itemRows = [];
        $sort = 0;
        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $product = $productMap->get($productId);
            if (! $product) {
                continue;
            }
            $qty = (int) $item['borrow_qty'];
            if ($checkStock) {
                $stockQty = (int) ($stockMap->get($productId)?->quantity ?? 0);
                if ($qty > $stockQty) {
                    throw new \RuntimeException("商品【{$product->name}】借货数量{$qty}超过库存{$stockQty}");
                }
            }
            $unitPrice = (float) ($item['unit_price'] ?? $product->price_small ?? 0);
            $amount = round($qty * $unitPrice, 2);

            $itemRows[] = [
                'product_id' => $productId,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'spec' => $product->spec,
                'unit' => $product->price_unit_small,
                'borrow_qty' => $qty,
                'returned_qty' => 0,
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
            throw new \RuntimeException('没有有效的商品明细');
        }

        return $itemRows;
    }

    private function increaseBorrowBalance(int $customerId, int $productId, int $qty): void
    {
        $balance = CustomerBorrowBalance::where('customer_id', $customerId)
            ->where('product_id', $productId)->lockForUpdate()->first();
        if ($balance) {
            $balance->update(['qty' => (int) $balance->qty + $qty, 'updated_at' => now()]);
        } else {
            CustomerBorrowBalance::create(['customer_id' => $customerId, 'product_id' => $productId, 'qty' => $qty]);
        }
    }

    private function decreaseBorrowBalance(int $customerId, int $productId, int $qty): void
    {
        $balance = CustomerBorrowBalance::where('customer_id', $customerId)
            ->where('product_id', $productId)->lockForUpdate()->first();
        if (! $balance) {
            return;
        }
        $newQty = (int) $balance->qty - $qty;
        if ($newQty <= 0) {
            $balance->delete();
        } else {
            $balance->update(['qty' => $newQty, 'updated_at' => now()]);
        }
    }

    private function writeOperationLog(BorrowOrder $order, ?int $adminId, string $adminName, string $action, string $actionLabel, ?string $fromStatus, ?string $toStatus, ?string $detail = null): void
    {
        DB::table('order_operation_logs')->insert([
            'order_id' => $order->id,
            'order_no' => $order->borrow_no,
            'order_type' => 'borrow_order',
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
