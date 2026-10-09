<?php

namespace Modules\Exchange\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Exchange\Models\ExchangeOrder;
use Modules\Exchange\Models\ExchangeOrderItem;
use Modules\Stock\Exceptions\StockRuleException;
use Modules\Stock\Services\StockService;

/**
 * 换货单（ExchangeOrder）
 *
 * 客户用 A 商品换 B 商品：换出商品退回仓库，换入商品出库，差价多退少补。
 * 状态机：draft → pending(待审核) → approved(已审核，库存生效) / cancelled；reject 回 draft。
 * 差价：>0 客户补款（收款方式），<0 退款给客户（退款方式），=0 等价交换。
 */
class ExchangeOrderController extends Controller
{
    use ResponseTrait;

    public function __construct(private StockService $stockService) {}

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
        $query = $this->applyFilters(ExchangeOrder::with(['customer', 'warehouse']), $request);

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        return $this->paginated($query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page));
    }

    public function export(Request $request)
    {
        $list = $this->applyFilters(ExchangeOrder::with(['customer', 'warehouse']), $request)
            ->orderByDesc('id')->get();

        $csv = "\u{FEFF}换货单列表\n\n";
        $csv .= "换货日期,换货单号,客户,仓库,业务员,换出种类,换出数量,换入种类,换入数量,差价,结算,状态\n";

        foreach ($list as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $r->exchange_date?->format('Y-m-d') ?? '',
                $this->csvCell($r->exchange_no),
                $this->csvCell($r->customer_name ?? ''),
                $this->csvCell($r->warehouse_name ?? ''),
                $this->csvCell($r->salesman_name ?? ''),
                $r->total_kinds_out,
                $r->total_qty_out,
                $r->total_kinds_in,
                $r->total_qty_in,
                number_format((float) $r->diff_amount, 2, '.', ''),
                $this->settleLabel($r),
                $this->statusLabel($r->status)
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="exchange_orders.csv"',
        ]);
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('exchange_no')) {
            $query->where('exchange_no', 'like', '%'.trim((string) $request->input('exchange_no')).'%');
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
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('start_date')) {
            $query->whereDate('exchange_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('exchange_date', '<=', $request->input('end_date'));
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
        return ExchangeOrder::statusLabel($status);
    }

    private function settleLabel(ExchangeOrder $order): string
    {
        if ((float) $order->diff_amount > 0.01) {
            return match ($order->payment_method) {
                'cash' => '现金收款',
                'wechat' => '微信收款',
                'alipay' => '支付宝收款',
                'bank' => '银行卡收款',
                'credit' => '挂账',
                default => '客户补款',
            };
        }
        if ((float) $order->diff_amount < -0.01) {
            return match ($order->refund_method) {
                'cash_return' => '现金退回',
                'offset' => '冲抵应收',
                default => '退款给客户',
            };
        }

        return '等价交换';
    }

    public function show($id)
    {
        $order = ExchangeOrder::with(['items.productOut', 'items.productIn', 'customer', 'warehouse'])->find($id);
        if (! $order) {
            return $this->notFound('换货单不存在');
        }

        return $this->success($order);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'exchange_date' => 'nullable|date',
            'exchange_reason' => 'nullable|string|max:40',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id_out' => 'required|exists:products,id',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.unit_price_out' => 'nullable|numeric|min:0',
            'items.*.product_id_in' => 'required|exists:products,id',
            'items.*.unit_price_in' => 'nullable|numeric|min:0',
            'items.*.remark' => 'nullable|string|max:255',
        ]);

        [$adminId, $adminName] = $this->currentAdmin();

        try {
            return DB::transaction(function () use ($validated, $adminId, $adminName) {
                $whId = (int) $validated['warehouse_id'];
                $customerId = (int) $validated['customer_id'];

                $allProductIds = collect($validated['items'])
                    ->flatMap(fn ($i) => [(int) $i['product_id_out'], (int) $i['product_id_in']])
                    ->unique();
                $stockMap = DB::table('stocks')->where('warehouse_id', $whId)->get()->keyBy('product_id');
                $productMap = DB::table('products')->whereIn('id', $allProductIds)->get()->keyBy('id');

                $itemRows = $this->buildItemRows($validated['items'], $productMap, $stockMap, true);

                $order = ExchangeOrder::create([
                    'exchange_no' => $this->generateNo('HH', 'exchange_orders', 'exchange_no'),
                    'customer_id' => $customerId,
                    'customer_name' => DB::table('customers')->where('id', $customerId)->value('name'),
                    'warehouse_id' => $whId,
                    'warehouse_name' => DB::table('warehouses')->where('id', $whId)->value('name'),
                    'salesman_id' => $adminId,
                    'salesman_name' => $adminName,
                    'exchange_date' => $validated['exchange_date'] ?? now()->toDateString(),
                    'exchange_reason' => $validated['exchange_reason'] ?? null,
                    'total_kinds_out' => $itemRows['kinds_out'],
                    'total_kinds_in' => $itemRows['kinds_in'],
                    'total_qty_out' => $itemRows['qty_out'],
                    'total_qty_in' => $itemRows['qty_in'],
                    'amount_out' => round($itemRows['amount_out'], 2),
                    'amount_in' => round($itemRows['amount_in'], 2),
                    'diff_amount' => round($itemRows['amount_in'] - $itemRows['amount_out'], 2),
                    'status' => ExchangeOrder::STATUS_DRAFT,
                    'created_by' => $adminId,
                    'creator_name' => $adminName,
                    'remark' => $validated['remark'] ?? null,
                ]);

                foreach ($itemRows['rows'] as &$row) {
                    $row['order_id'] = $order->id;
                }
                unset($row);
                ExchangeOrderItem::insert($itemRows['rows']);

                return $this->created($order->load('items', 'customer', 'warehouse'), '草稿已保存');
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function update(Request $request, $id)
    {
        $order = ExchangeOrder::find($id);
        if (! $order) {
            return $this->notFound('换货单不存在');
        }
        if ($order->status !== ExchangeOrder::STATUS_DRAFT) {
            return $this->error('只有草稿状态的换货单才能修改', 422);
        }

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'exchange_date' => 'nullable|date',
            'exchange_reason' => 'nullable|string|max:40',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id_out' => 'required|exists:products,id',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.unit_price_out' => 'nullable|numeric|min:0',
            'items.*.product_id_in' => 'required|exists:products,id',
            'items.*.unit_price_in' => 'nullable|numeric|min:0',
            'items.*.remark' => 'nullable|string|max:255',
        ]);

        try {
            return DB::transaction(function () use ($order, $validated) {
                $whId = (int) $validated['warehouse_id'];
                $customerId = (int) $validated['customer_id'];
                $allProductIds = collect($validated['items'])
                    ->flatMap(fn ($i) => [(int) $i['product_id_out'], (int) $i['product_id_in']])
                    ->unique();
                $stockMap = DB::table('stocks')->where('warehouse_id', $whId)->get()->keyBy('product_id');
                $productMap = DB::table('products')->whereIn('id', $allProductIds)->get()->keyBy('id');

                $itemRows = $this->buildItemRows($validated['items'], $productMap, $stockMap, true);

                $order->items()->delete();
                foreach ($itemRows['rows'] as &$row) {
                    $row['order_id'] = $order->id;
                }
                unset($row);
                ExchangeOrderItem::insert($itemRows['rows']);

                $order->update([
                    'customer_id' => $customerId,
                    'customer_name' => DB::table('customers')->where('id', $customerId)->value('name'),
                    'warehouse_id' => $whId,
                    'warehouse_name' => DB::table('warehouses')->where('id', $whId)->value('name'),
                    'exchange_date' => $validated['exchange_date'] ?? $order->exchange_date?->toDateString() ?? now()->toDateString(),
                    'exchange_reason' => $validated['exchange_reason'] ?? null,
                    'total_kinds_out' => $itemRows['kinds_out'],
                    'total_kinds_in' => $itemRows['kinds_in'],
                    'total_qty_out' => $itemRows['qty_out'],
                    'total_qty_in' => $itemRows['qty_in'],
                    'amount_out' => round($itemRows['amount_out'], 2),
                    'amount_in' => round($itemRows['amount_in'], 2),
                    'diff_amount' => round($itemRows['amount_in'] - $itemRows['amount_out'], 2),
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
        $order = ExchangeOrder::find($id);
        if (! $order) {
            return $this->notFound('换货单不存在');
        }
        if ($order->status !== ExchangeOrder::STATUS_DRAFT) {
            return $this->error('当前状态不能删除', 422);
        }

        $order->items()->delete();
        $order->delete();

        return $this->success(null, '已删除');
    }

    /** 提交审核（draft → pending）：校验原因与差价结算方式 */
    public function submit($id, Request $request)
    {
        $order = ExchangeOrder::with('items')->find($id);
        if (! $order) {
            return $this->notFound('换货单不存在');
        }
        if ($order->status !== ExchangeOrder::STATUS_DRAFT) {
            return $this->error('只有草稿状态的换货单才能提交', 422);
        }
        if (empty($order->exchange_reason)) {
            return $this->error('请先选择换货原因', 422);
        }

        $diff = (float) $order->diff_amount;
        if ($diff > 0.01) {
            $pm = $request->input('payment_method', $order->payment_method);
            if (! in_array($pm, [ExchangeOrder::PAYMENT_CASH, ExchangeOrder::PAYMENT_WECHAT, ExchangeOrder::PAYMENT_ALIPAY, ExchangeOrder::PAYMENT_BANK, ExchangeOrder::PAYMENT_CREDIT])) {
                return $this->error('差价为正，请选择收款方式', 422);
            }
            $order->payment_method = $pm;
            $order->refund_method = null;
        } elseif ($diff < -0.01) {
            $rm = $request->input('refund_method', $order->refund_method);
            if (! in_array($rm, [ExchangeOrder::REFUND_CASH_RETURN, ExchangeOrder::REFUND_OFFSET])) {
                return $this->error('差价为负，请选择退款方式', 422);
            }
            $order->refund_method = $rm;
            $order->payment_method = null;
        } else {
            $order->payment_method = null;
            $order->refund_method = null;
        }

        $order->status = ExchangeOrder::STATUS_PENDING;
        $order->save();

        return $this->success($order, '已提交，等待审核');
    }

    /** 审核通过（pending → approved）：换出入库 + 换入出库 + 差价结算 */
    public function approve($id)
    {
        $order = ExchangeOrder::with('items')->find($id);
        if (! $order) {
            return $this->notFound('换货单不存在');
        }
        if ($order->status === ExchangeOrder::STATUS_APPROVED) {
            return $this->success($order, '已审核，无需重复操作');
        }
        if ($order->status !== ExchangeOrder::STATUS_PENDING) {
            return $this->error('只有待审核的换货单才能审核', 422);
        }

        [$adminId, $adminName] = $this->currentAdmin();

        try {
            DB::transaction(function () use ($order, $adminId, $adminName) {
                $whId = (int) $order->warehouse_id;

                foreach ($order->items as $item) {
                    $qty = (int) $item->qty;
                    if ($qty <= 0) {
                        continue;
                    }
                    // 换出商品退回仓库
                    $this->stockService->stockIn(
                        (int) $item->product_id_out,
                        $whId,
                        $qty,
                        null,
                        (int) $order->id,
                        'ExchangeOrder'
                    );
                    // 换入商品出库
                    $this->stockService->stockOut(
                        (int) $item->product_id_in,
                        $whId,
                        $qty,
                        (int) $order->id,
                        'ExchangeOrder',
                        '换货换入出库'
                    );
                }

                $this->settleDiff($order, $adminId);

                $order->update(['status' => ExchangeOrder::STATUS_APPROVED, 'approved_at' => now()]);
                $this->writeOperationLog($order, $adminId, $adminName, 'approve', '换货审核通过', ExchangeOrder::STATUS_PENDING, ExchangeOrder::STATUS_APPROVED, '差价¥'.number_format((float) $order->diff_amount, 2));
            });
        } catch (StockRuleException $e) {
            return $this->error('审核换货失败：'.$e->getMessage(), 422);
        }

        return $this->success($order->load('items', 'customer', 'warehouse'), '换货已审核，库存已调整');
    }

    /** 驳回（pending → draft） */
    public function reject($id, Request $request)
    {
        $order = ExchangeOrder::find($id);
        if (! $order) {
            return $this->notFound('换货单不存在');
        }
        if ($order->status !== ExchangeOrder::STATUS_PENDING) {
            return $this->error('只有待审核的换货单才能驳回', 422);
        }
        $request->validate(['reject_reason' => 'required|string|max:500']);

        $order->update([
            'status' => ExchangeOrder::STATUS_DRAFT,
            'rejected_at' => now(),
            'reject_reason' => $request->input('reject_reason'),
        ]);

        return $this->success($order, '已驳回，可修改后重新提交');
    }

    public function cancel($id, Request $request)
    {
        $order = ExchangeOrder::find($id);
        if (! $order) {
            return $this->notFound('换货单不存在');
        }
        if (! in_array($order->status, [ExchangeOrder::STATUS_DRAFT, ExchangeOrder::STATUS_PENDING])) {
            return $this->error('当前状态不能取消', 422);
        }
        $request->validate(['cancel_reason' => 'required|string|max:500']);

        $order->update([
            'status' => ExchangeOrder::STATUS_CANCELLED,
            'reject_reason' => $request->input('cancel_reason'),
        ]);

        return $this->success($order, '已取消');
    }

    private function settleDiff(ExchangeOrder $order, ?int $adminId): void
    {
        $diff = (float) $order->diff_amount;
        $date = $order->exchange_date?->toDateString() ?? now()->toDateString();

        if ($diff > 0.01) {
            if ($order->payment_method === ExchangeOrder::PAYMENT_CREDIT) {
                DB::table('customers')->where('id', $order->customer_id)->increment('balance', $diff);
            } else {
                $method = match ($order->payment_method) {
                    'wechat' => '微信',
                    'alipay' => '支付宝',
                    'bank' => '银行卡',
                    default => '现金',
                };
                $this->writeCashFlow('receive', $diff, (int) $order->id, 'ExchangeOrder', $date, '换货补款-'.$order->exchange_no, $adminId, $method);
            }
        } elseif ($diff < -0.01) {
            if ($order->refund_method === ExchangeOrder::REFUND_OFFSET) {
                DB::table('customers')->where('id', $order->customer_id)->decrement('balance', abs($diff));
            } else {
                $this->writeCashFlow('pay', abs($diff), (int) $order->id, 'ExchangeOrder', $date, '换货退款-'.$order->exchange_no, $adminId, '现金');
            }
        }
    }

    private function buildItemRows(array $items, $productMap, $stockMap, bool $checkStock): array
    {
        $rows = [];
        $kindsOut = 0;
        $kindsIn = 0;
        $qtyOut = 0;
        $qtyIn = 0;
        $amountOut = 0.0;
        $amountIn = 0.0;
        $sort = 0;

        foreach ($items as $item) {
            $productOut = $productMap->get((int) $item['product_id_out']);
            $productIn = $productMap->get((int) $item['product_id_in']);
            if (! $productOut || ! $productIn) {
                continue;
            }
            $qty = (int) $item['qty'];
            if ($checkStock) {
                $outStock = (int) ($stockMap->get((int) $item['product_id_in'])?->quantity ?? 0);
                if ($qty > $outStock) {
                    throw new \RuntimeException("换入商品【{$productIn->name}】数量{$qty}超过库存{$outStock}");
                }
            }
            $unitPriceOut = (float) ($item['unit_price_out'] ?? $productOut->price_small ?? 0);
            $unitPriceIn = (float) ($item['unit_price_in'] ?? $productIn->price_small ?? 0);
            $itemOut = round($qty * $unitPriceOut, 2);
            $itemIn = round($qty * $unitPriceIn, 2);

            $rows[] = [
                'product_id_out' => (int) $item['product_id_out'],
                'product_name_out' => $productOut->name,
                'spec_out' => $productOut->spec,
                'unit_out' => $productOut->price_unit_small,
                'qty' => $qty,
                'unit_price_out' => $unitPriceOut,
                'amount_out' => $itemOut,
                'product_id_in' => (int) $item['product_id_in'],
                'product_name_in' => $productIn->name,
                'spec_in' => $productIn->spec,
                'unit_in' => $productIn->price_unit_small,
                'unit_price_in' => $unitPriceIn,
                'amount_in' => $itemIn,
                'diff_amount' => round($itemIn - $itemOut, 2),
                'remark' => $item['remark'] ?? null,
                'sort' => $sort,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $sort++;
            $kindsOut++;
            $kindsIn++;
            $qtyOut += $qty;
            $qtyIn += $qty;
            $amountOut += $itemOut;
            $amountIn += $itemIn;
        }

        if (empty($rows)) {
            throw new \RuntimeException('没有有效的商品明细');
        }

        return [
            'rows' => $rows,
            'kinds_out' => $kindsOut,
            'kinds_in' => $kindsIn,
            'qty_out' => $qtyOut,
            'qty_in' => $qtyIn,
            'amount_out' => $amountOut,
            'amount_in' => $amountIn,
        ];
    }

    private function writeCashFlow(string $type, float $amount, ?int $relatedId, ?string $relatedType, string $date, string $remark, ?int $adminId, string $paymentMethod = '现金'): void
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
            'payment_method' => $paymentMethod,
            'remark' => $remark,
            'created_by' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function writeOperationLog(ExchangeOrder $order, ?int $adminId, string $adminName, string $action, string $actionLabel, ?string $fromStatus, ?string $toStatus, ?string $detail = null): void
    {
        DB::table('order_operation_logs')->insert([
            'order_id' => $order->id,
            'order_no' => $order->exchange_no,
            'order_type' => 'exchange_order',
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
