<?php

namespace Modules\VanSales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Stock\Exceptions\StockRuleException;
use Modules\Stock\Services\StockService;
use Modules\VanSales\Models\VanReturnOrder;
use Modules\VanSales\Models\VanReturnOrderItem;

/**
 * 车销退货单（VanReturnOrder）
 *
 * 客户现场退货：退货运回车上库存，按退款方式处理资金。
 *
 * 状态机：draft → approved(入库+退款，不可逆) / cancelled
 *
 * 退款方式：cash=现金退回(写 cash_flows pay 负数) /
 *           offset=冲抵应收(减 customers.balance) /
 *           credit=挂账(减 customers.balance，后续对账)
 */
class VanReturnOrderController extends Controller
{
    use ResponseTrait;

    public function __construct(private StockService $stockService) {}

    /** 指定车上仓的商品（新增退货单时加载明细用） */
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
        $query = $this->applyFilters(VanReturnOrder::with(['customer', 'vehicle']), $request);

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        return $this->paginated($query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page));
    }

    /** 导出 */
    public function export(Request $request)
    {
        $list = $this->applyFilters(VanReturnOrder::with(['customer', 'vehicle']), $request)
            ->orderByDesc('id')->get();

        $csv = "\u{FEFF}车销退货单列表\n\n";
        $csv .= "退货日期,退货单号,业务员,客户,车牌号,商品总数,退货金额,退款金额,冲抵应收,退款方式,退货原因,状态\n";

        foreach ($list as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $r->return_date?->format('Y-m-d') ?? '',
                $this->csvCell($r->return_no),
                $this->csvCell($r->salesman_name ?? ''),
                $this->csvCell($r->customer_name ?? $r->customer?->name ?? ''),
                $this->csvCell($r->vehicle?->plate_no ?? ''),
                $r->total_qty,
                number_format((float) $r->total_amount, 2, '.', ''),
                number_format((float) $r->refund_amount, 2, '.', ''),
                number_format((float) $r->receivable_offset, 2, '.', ''),
                $this->refundLabel($r->refund_method),
                $this->reasonLabel($r->return_reason),
                $this->statusLabel($r->status)
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="van_return_orders.csv"',
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
            'approved' => '已确认',
            'cancelled' => '已取消',
            default => $status,
        };
    }

    private function refundLabel(string $method): string
    {
        return match ($method) {
            'cash' => '现金退回',
            'offset' => '冲抵应收',
            'credit' => '挂账',
            default => $method,
        };
    }

    private function reasonLabel(?string $reason): string
    {
        return match ($reason) {
            'quality' => '质量问题',
            'expiry' => '临期',
            'damage' => '破损',
            'oversend' => '多送',
            'other' => '其他',
            null => '',
            default => $reason,
        };
    }

    /** 详情 */
    public function show($id)
    {
        $order = VanReturnOrder::with(['items.product', 'customer', 'vehicle'])->find($id);
        if (! $order) {
            return $this->notFound('退货单不存在');
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
            'return_date' => 'nullable|date',
            'return_reason' => 'nullable|in:quality,expiry,damage,oversend,other',
            'refund_method' => 'nullable|in:cash,offset,credit',
            'refund_amount' => 'nullable|numeric|min:0',
            'receivable_offset' => 'nullable|numeric|min:0',
            'visit_log_id' => 'nullable|integer',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.return_qty' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.source_sale_order_id' => 'nullable|integer',
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
                    'source_sale_order_id' => $item['source_sale_order_id'] ?? null,
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

            $refundMethod = $validated['refund_method'] ?? 'cash';
            $refundAmount = (float) ($validated['refund_amount'] ?? $totalAmount);
            $receivableOffset = (float) ($validated['receivable_offset'] ?? 0);

            $order = VanReturnOrder::create([
                'return_no' => $this->generateNo('VXT', 'van_return_orders', 'return_no'),
                'salesman_id' => $adminId,
                'salesman_name' => $adminName,
                'customer_id' => $customerId,
                'customer_name' => DB::table('customers')->where('id', $customerId)->value('name'),
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'vehicle_warehouse_id' => $vwId,
                'return_date' => $validated['return_date'] ?? now()->toDateString(),
                'return_reason' => $validated['return_reason'] ?? null,
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
                'refund_method' => $refundMethod,
                'refund_amount' => $refundAmount,
                'receivable_offset' => $receivableOffset,
                'status' => VanReturnOrder::STATUS_DRAFT,
                'visit_log_id' => $validated['visit_log_id'] ?? null,
                'created_by' => $adminId,
                'creator_name' => $adminName,
                'remark' => $validated['remark'] ?? null,
            ]);

            foreach ($itemRows as &$row) {
                $row['order_id'] = $order->id;
            }
            unset($row);
            VanReturnOrderItem::insert($itemRows);

            return $this->created($order->load('items', 'customer', 'vehicle'), '草稿已保存');
        });
    }

    /** 更新草稿 */
    public function update(Request $request, $id)
    {
        $order = VanReturnOrder::find($id);
        if (! $order) {
            return $this->notFound('退货单不存在');
        }
        if ($order->status !== VanReturnOrder::STATUS_DRAFT) {
            return $this->error('当前状态不能修改', 422);
        }

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'return_date' => 'nullable|date',
            'return_reason' => 'nullable|in:quality,expiry,damage,oversend,other',
            'refund_method' => 'nullable|in:cash,offset,credit',
            'refund_amount' => 'nullable|numeric|min:0',
            'receivable_offset' => 'nullable|numeric|min:0',
            'visit_log_id' => 'nullable|integer',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.return_qty' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.source_sale_order_id' => 'nullable|integer',
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
                    'source_sale_order_id' => $item['source_sale_order_id'] ?? null,
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
            VanReturnOrderItem::insert($itemRows);
            $order->update([
                'customer_id' => $customerId,
                'customer_name' => DB::table('customers')->where('id', $customerId)->value('name'),
                'return_date' => $validated['return_date'] ?? $order->return_date?->toDateString() ?? now()->toDateString(),
                'return_reason' => $validated['return_reason'] ?? $order->return_reason,
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
                'refund_method' => $validated['refund_method'] ?? $order->refund_method,
                'refund_amount' => $validated['refund_amount'] ?? $order->refund_amount,
                'receivable_offset' => $validated['receivable_offset'] ?? $order->receivable_offset,
                'visit_log_id' => $validated['visit_log_id'] ?? $order->visit_log_id,
                'remark' => array_key_exists('remark', $validated) ? $validated['remark'] : $order->remark,
            ]);

            return $this->success($order->load('items', 'customer', 'vehicle'), '草稿已更新');
        });
    }

    /** 删除（仅 draft） */
    public function destroy($id)
    {
        $order = VanReturnOrder::find($id);
        if (! $order) {
            return $this->notFound('退货单不存在');
        }
        if ($order->status !== VanReturnOrder::STATUS_DRAFT) {
            return $this->error('当前状态不能删除', 422);
        }

        $order->items()->delete();
        $order->delete();

        return $this->success(null, '已删除');
    }

    /**
     * 确认退货（draft → approved）：事务内 退货到车上库存 + 退款/冲抵应收 + 现金流水。
     */
    public function approve($id)
    {
        $order = VanReturnOrder::with('items')->find($id);
        if (! $order) {
            return $this->notFound('退货单不存在');
        }
        if ($order->status === VanReturnOrder::STATUS_APPROVED) {
            return $this->success($order, '已确认，无需重复操作');
        }
        if ($order->status !== VanReturnOrder::STATUS_DRAFT) {
            return $this->error('只有草稿状态的退货单才能确认', 422);
        }

        [$adminId, $adminName] = $this->currentAdmin();

        try {
            DB::transaction(function () use ($order, $adminId, $adminName) {
                // 退货到车上库存
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
                        'VanReturnOrder'
                    );
                }

                $refundAmount = (float) $order->refund_amount;
                $receivableOffset = (float) $order->receivable_offset;
                $returnDate = $order->return_date?->toDateString() ?? now()->toDateString();

                if ($order->refund_method === VanReturnOrder::REFUND_CASH && $refundAmount > 0) {
                    // 现金退回：现金给客户，记现金流水（负数=支出），不动应收余额
                    $this->writeCashFlow('pay', -$refundAmount, $order->id, 'VanReturnOrder', $returnDate, '车销退货退款-'.$order->return_no, $adminId);
                }
                if (in_array($order->refund_method, [VanReturnOrder::REFUND_OFFSET, VanReturnOrder::REFUND_CREDIT], true) && $receivableOffset > 0) {
                    // 冲抵应收/挂账：减客户欠款（退货让客户对我们的应收减少）
                    DB::table('customers')->where('id', $order->customer_id)->decrement('balance', $receivableOffset);
                }

                // 关联拜访单
                if ($order->visit_log_id) {
                    DB::table('visit_logs')->where('id', $order->visit_log_id)->update(['van_return_order_id' => $order->id]);
                }

                $order->update(['status' => VanReturnOrder::STATUS_APPROVED, 'approved_at' => now()]);
                $this->writeOperationLog($order, $adminId, $adminName, 'approve', '确认退货', VanReturnOrder::STATUS_DRAFT, VanReturnOrder::STATUS_APPROVED, '退货¥'.number_format((float) $order->total_amount, 2).'，退款¥'.number_format($refundAmount, 2).'，冲抵应收¥'.number_format($receivableOffset, 2));
            });
        } catch (StockRuleException $e) {
            return $this->error('确认退货失败：'.$e->getMessage(), 422);
        }

        return $this->success($order->load('items', 'customer', 'vehicle'), '退货已确认，商品已退回车上库存');
    }

    /** 取消（仅 draft） */
    public function cancel($id)
    {
        $order = VanReturnOrder::find($id);
        if (! $order) {
            return $this->notFound('退货单不存在');
        }
        if ($order->status !== VanReturnOrder::STATUS_DRAFT) {
            return $this->error('当前状态不能取消', 422);
        }

        $order->update(['status' => VanReturnOrder::STATUS_CANCELLED]);

        return $this->success($order, '已取消');
    }

    private function writeCashFlow(string $type, float $amount, ?int $relatedId, ?string $relatedType, string $date, string $remark, ?int $adminId): void
    {
        DB::table('cash_flows')->insert([
            'flow_no' => 'CF'.date('YmdHis').strtoupper(Str::random(4)),
            'flow_type' => $type,
            'customer_id' => null,
            'supplier_id' => null,
            'related_id' => $relatedId,
            'related_type' => $relatedType,
            'flow_date' => $date,
            'amount' => $amount,
            'payment_method' => '现金',
            'remark' => $remark,
            'created_by' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function writeOperationLog(VanReturnOrder $order, ?int $adminId, string $adminName, string $action, string $actionLabel, ?string $fromStatus, ?string $toStatus, ?string $detail = null): void
    {
        DB::table('order_operation_logs')->insert([
            'order_id' => $order->id,
            'order_no' => $order->return_no,
            'order_type' => 'van_return_order',
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
