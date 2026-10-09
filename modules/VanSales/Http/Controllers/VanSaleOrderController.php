<?php

namespace Modules\VanSales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Stock\Exceptions\StockRuleException;
use Modules\Stock\Services\PriceService;
use Modules\Stock\Services\StockService;
use Modules\VanSales\Models\VanSaleOrder;
use Modules\VanSales\Models\VanSaleOrderItem;

/**
 * 车销销售单（VanSaleOrder）
 *
 * 业务员在客户处现场销售，从车上库存扣减，现场收款或挂账。
 *
 * 状态机：draft → approved(扣车上库存+收款/挂账，不可逆) / cancelled；冲正走退货单
 *
 * 取价：PriceService::resolve(?customerId, productId, standardPrice) 按客户等级自动带出。
 * 收款：现金/微信/支付宝/银行卡 → 写 receives(status=1) + cash_flows；
 *       挂账 → customers.balance increment（参考 ReceiveController L226）。
 *
 * 模板对照：StockAdjustController + ReceiveController + SalesReturnController
 */
class VanSaleOrderController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private StockService $stockService,
        private PriceService $priceService,
    ) {}

    /** 指定车上仓的商品库存（新增销售单时加载明细用） */
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
        $query = $this->applyFilters(VanSaleOrder::with(['customer', 'vehicle']), $request);

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        return $this->paginated($paginator);
    }

    /** 导出 */
    public function export(Request $request)
    {
        $list = $this->applyFilters(VanSaleOrder::with(['customer', 'vehicle']), $request)
            ->orderByDesc('id')->get();

        $csv = "\u{FEFF}车销销售单列表\n\n";
        $csv .= "销售日期,销售单号,业务员,客户,车牌号,商品总数,总金额,收款金额,付款方式,状态\n";

        foreach ($list as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $r->sale_date?->format('Y-m-d') ?? '',
                $this->csvCell($r->order_no),
                $this->csvCell($r->salesman_name ?? ''),
                $this->csvCell($r->customer_name ?? $r->customer?->name ?? ''),
                $this->csvCell($r->vehicle?->plate_no ?? ''),
                $r->total_qty,
                number_format((float) $r->total_amount, 2, '.', ''),
                number_format((float) $r->paid_amount, 2, '.', ''),
                $this->paymentLabel($r->payment_method),
                $this->statusLabel($r->status)
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="van_sale_orders.csv"',
        ]);
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('order_no')) {
            $query->where('order_no', 'like', '%'.trim((string) $request->input('order_no')).'%');
        }
        if ($request->filled('salesman_id')) {
            $query->where('salesman_id', (int) $request->input('salesman_id'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->input('customer_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('start_date')) {
            $query->whereDate('sale_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('sale_date', '<=', $request->input('end_date'));
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

    private function paymentLabel(string $method): string
    {
        return match ($method) {
            'cash' => '现金',
            'wechat' => '微信',
            'alipay' => '支付宝',
            'card' => '银行卡',
            'credit' => '挂账',
            default => $method,
        };
    }

    /** 详情 */
    public function show($id)
    {
        $order = VanSaleOrder::with(['items.product', 'customer', 'vehicle'])->find($id);
        if (! $order) {
            return $this->notFound('销售单不存在');
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
            'sale_date' => 'nullable|date',
            'payment_method' => 'nullable|in:cash,wechat,alipay,card,credit',
            'paid_amount' => 'nullable|numeric|min:0',
            'visit_log_id' => 'nullable|integer',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.sale_qty' => 'required|integer|min:1',
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
                $stock = $stockMap->get($productId);
                $stockQty = (int) ($stock?->quantity ?? 0);
                $saleQty = (int) $item['sale_qty'];
                if ($saleQty > $stockQty) {
                    return $this->error("商品【{$product->name}】销售数量{$saleQty}超过车上库存{$stockQty}", 422);
                }

                // 取价：前端传了 unit_price 就用前端值，否则按客户等级自动取价
                if (isset($item['unit_price']) && $item['unit_price'] > 0) {
                    $unitPrice = (float) $item['unit_price'];
                    $priceSource = 'manual';
                } else {
                    $resolved = $this->priceService->resolve($customerId, $productId, (float) $product->price_small);
                    $unitPrice = (float) ($resolved['price'] ?? $product->price_small ?? 0);
                    $priceSource = $resolved['source'] ?? 'standard';
                }
                $amount = round($saleQty * $unitPrice, 2);

                $totalQty += $saleQty;
                $totalAmount += $amount;

                $itemRows[] = [
                    'product_id' => $productId,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'spec' => $product->spec,
                    'unit' => $product->price_unit_small,
                    'stock_qty' => $stockQty,
                    'sale_qty' => $saleQty,
                    'unit_price' => $unitPrice,
                    'price_source' => $priceSource,
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

            $paymentMethod = $validated['payment_method'] ?? 'cash';
            $paidAmount = (float) ($validated['paid_amount'] ?? $totalAmount);

            $order = VanSaleOrder::create([
                'order_no' => $this->generateNo('VXS', 'van_sale_orders', 'order_no'),
                'salesman_id' => $adminId,
                'salesman_name' => $adminName,
                'customer_id' => $customerId,
                'customer_name' => DB::table('customers')->where('id', $customerId)->value('name'),
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'vehicle_warehouse_id' => $vwId,
                'sale_date' => $validated['sale_date'] ?? now()->toDateString(),
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
                'paid_amount' => $paidAmount,
                'payment_method' => $paymentMethod,
                'status' => VanSaleOrder::STATUS_DRAFT,
                'visit_log_id' => $validated['visit_log_id'] ?? null,
                'remark' => $validated['remark'] ?? null,
                'created_by' => $adminId,
                'creator_name' => $adminName,
            ]);

            foreach ($itemRows as &$row) {
                $row['order_id'] = $order->id;
            }
            unset($row);
            VanSaleOrderItem::insert($itemRows);

            return $this->created($order->load('items', 'customer', 'vehicle'), '草稿已保存');
        });
    }

    /** 更新草稿 */
    public function update(Request $request, $id)
    {
        $order = VanSaleOrder::find($id);
        if (! $order) {
            return $this->notFound('销售单不存在');
        }
        if ($order->status !== VanSaleOrder::STATUS_DRAFT) {
            return $this->error('当前状态不能修改', 422);
        }

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'sale_date' => 'nullable|date',
            'payment_method' => 'nullable|in:cash,wechat,alipay,card,credit',
            'paid_amount' => 'nullable|numeric|min:0',
            'visit_log_id' => 'nullable|integer',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.sale_qty' => 'required|integer|min:1',
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
                $stock = $stockMap->get($productId);
                $stockQty = (int) ($stock?->quantity ?? 0);
                $saleQty = (int) $item['sale_qty'];
                if ($saleQty > $stockQty) {
                    return $this->error("商品【{$product->name}】销售数量超过车上库存", 422);
                }

                if (isset($item['unit_price']) && $item['unit_price'] > 0) {
                    $unitPrice = (float) $item['unit_price'];
                    $priceSource = 'manual';
                } else {
                    $resolved = $this->priceService->resolve($customerId, $productId, (float) $product->price_small);
                    $unitPrice = (float) ($resolved['price'] ?? $product->price_small ?? 0);
                    $priceSource = $resolved['source'] ?? 'standard';
                }
                $amount = round($saleQty * $unitPrice, 2);
                $totalQty += $saleQty;
                $totalAmount += $amount;

                $itemRows[] = [
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'spec' => $product->spec,
                    'unit' => $product->price_unit_small,
                    'stock_qty' => $stockQty,
                    'sale_qty' => $saleQty,
                    'unit_price' => $unitPrice,
                    'price_source' => $priceSource,
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
            VanSaleOrderItem::insert($itemRows);
            $order->update([
                'customer_id' => $customerId,
                'customer_name' => DB::table('customers')->where('id', $customerId)->value('name'),
                'sale_date' => $validated['sale_date'] ?? $order->sale_date?->toDateString() ?? now()->toDateString(),
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
                'paid_amount' => $validated['paid_amount'] ?? $totalAmount,
                'payment_method' => $validated['payment_method'] ?? $order->payment_method,
                'visit_log_id' => $validated['visit_log_id'] ?? $order->visit_log_id,
                'remark' => array_key_exists('remark', $validated) ? $validated['remark'] : $order->remark,
            ]);

            return $this->success($order->load('items', 'customer', 'vehicle'), '草稿已更新');
        });
    }

    /** 删除（仅 draft） */
    public function destroy($id)
    {
        $order = VanSaleOrder::find($id);
        if (! $order) {
            return $this->notFound('销售单不存在');
        }
        if ($order->status !== VanSaleOrder::STATUS_DRAFT) {
            return $this->error('当前状态不能删除', 422);
        }

        $order->items()->delete();
        $order->delete();

        return $this->success(null, '已删除');
    }

    /**
     * 确认销售（draft → approved）：事务内 扣车上库存 + 收款/挂账 + 现金流水。
     * 模板对照：ReceiveController L226（balance increment）+ SalesReturnController L386（cash_flows）
     */
    public function approve($id)
    {
        $order = VanSaleOrder::with('items')->find($id);
        if (! $order) {
            return $this->notFound('销售单不存在');
        }
        if ($order->status === VanSaleOrder::STATUS_APPROVED) {
            return $this->success($order, '已确认，无需重复操作');
        }
        if ($order->status !== VanSaleOrder::STATUS_DRAFT) {
            return $this->error('只有草稿状态的销售单才能确认', 422);
        }

        [$adminId, $adminName] = $this->currentAdmin();

        try {
            DB::transaction(function () use ($order, $adminId, $adminName) {
                // 扣车上库存
                foreach ($order->items as $item) {
                    $saleQty = (int) $item->sale_qty;
                    if ($saleQty <= 0) {
                        continue;
                    }
                    $this->stockService->stockOut(
                        (int) $item->product_id,
                        (int) $order->vehicle_warehouse_id,
                        $saleQty,
                        (int) $order->id,
                        'VanSaleOrder',
                        '车销销售出库'
                    );
                }

                $totalAmount = (float) $order->total_amount;
                $paidAmount = (float) $order->paid_amount;
                $saleDate = $order->sale_date?->toDateString() ?? now()->toDateString();

                if ($order->payment_method === VanSaleOrder::PAYMENT_CREDIT) {
                    // 挂账：增加客户应收（欠款）
                    DB::table('customers')->where('id', $order->customer_id)->increment('balance', $totalAmount);
                    $flowRemark = '车销挂账-'.$order->order_no;
                    $this->writeCashFlow('pay', -$totalAmount, $order->id, 'VanSaleOrder', $saleDate, $flowRemark, $adminId);
                } else {
                    // 现金/微信/支付宝/银行卡：写收款单（已确认）
                    DB::table('receives')->insert([
                        'receive_no' => $this->generateNo('QT', 'receives', 'receive_no'),
                        'receive_type' => 1,
                        'customer_id' => $order->customer_id,
                        'sales_order_id' => null,
                        'amount' => $paidAmount,
                        'receive_date' => $saleDate,
                        'payment_method' => $this->paymentLabel($order->payment_method),
                        'handler_id' => $adminId,
                        'remark' => '车销收款-'.$order->order_no,
                        'status' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $this->writeCashFlow('receive', $paidAmount, $order->id, 'VanSaleOrder', $saleDate, '车销收款-'.$order->order_no, $adminId);

                    // 挂账部分（收款<应收）
                    if ($paidAmount < $totalAmount - 0.01) {
                        $diff = $totalAmount - $paidAmount;
                        DB::table('customers')->where('id', $order->customer_id)->increment('balance', $diff);
                    }
                }

                // 关联拜访单
                if ($order->visit_log_id) {
                    DB::table('visit_logs')->where('id', $order->visit_log_id)->update(['van_sale_order_id' => $order->id]);
                }

                $order->update(['status' => VanSaleOrder::STATUS_APPROVED, 'approved_at' => now()]);
                $this->writeOperationLog($order, $adminId, $adminName, 'approve', '确认销售', VanSaleOrder::STATUS_DRAFT, VanSaleOrder::STATUS_APPROVED, '销售¥'.number_format($totalAmount, 2).'，收款¥'.number_format($paidAmount, 2));
            });
        } catch (StockRuleException $e) {
            return $this->error('确认销售失败：'.$e->getMessage(), 422);
        }

        return $this->success($order->load('items', 'customer', 'vehicle'), '销售已确认，车上库存已扣减');
    }

    /** 取消（仅 draft） */
    public function cancel($id)
    {
        $order = VanSaleOrder::find($id);
        if (! $order) {
            return $this->notFound('销售单不存在');
        }
        if ($order->status !== VanSaleOrder::STATUS_DRAFT) {
            return $this->error('当前状态不能取消', 422);
        }

        $order->update(['status' => VanSaleOrder::STATUS_CANCELLED]);

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

    private function writeOperationLog(VanSaleOrder $order, ?int $adminId, string $adminName, string $action, string $actionLabel, ?string $fromStatus, ?string $toStatus, ?string $detail = null): void
    {
        DB::table('order_operation_logs')->insert([
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'order_type' => 'van_sale_order',
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
