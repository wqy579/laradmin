<?php

namespace Modules\VanSales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Stock\Exceptions\StockRuleException;
use Modules\Stock\Services\StockService;
use Modules\VanSales\Models\VanExchangeOrder;
use Modules\VanSales\Models\VanExchangeOrderItem;

/**
 * 车销换货单（VanExchangeOrder）
 *
 * 现场换货：换出商品退回车上库存，换入商品从车上库存扣减，按差价结算。
 * diff_amount = 换入金额合计 - 换出金额合计；正数=客户补款，负数=退给客户。
 *
 * 状态机：draft → approved(换出入车+换出扣车+差价结算，不可逆) / cancelled
 */
class VanExchangeOrderController extends Controller
{
    use ResponseTrait;

    public function __construct(private StockService $stockService) {}

    /** 指定车上仓的商品（新增换货单时加载明细用） */
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
        $query = $this->applyFilters(VanExchangeOrder::with(['customer', 'vehicle']), $request);

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        return $this->paginated($query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page));
    }

    /** 导出 */
    public function export(Request $request)
    {
        $list = $this->applyFilters(VanExchangeOrder::with(['customer', 'vehicle']), $request)
            ->orderByDesc('id')->get();

        $csv = "\u{FEFF}车销换货单列表\n\n";
        $csv .= "换货日期,换货单号,业务员,客户,车牌号,差价,结算方式,状态\n";

        foreach ($list as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s\n",
                $r->exchange_date?->format('Y-m-d') ?? '',
                $this->csvCell($r->exchange_no),
                $this->csvCell($r->salesman_name ?? ''),
                $this->csvCell($r->customer_name ?? $r->customer?->name ?? ''),
                $this->csvCell($r->vehicle?->plate_no ?? ''),
                number_format((float) $r->diff_amount, 2, '.', ''),
                $this->settleLabel($r->settle_method),
                $this->statusLabel($r->status)
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="van_exchange_orders.csv"',
        ]);
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('exchange_no')) {
            $query->where('exchange_no', 'like', '%'.trim((string) $request->input('exchange_no')).'%');
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
        return match ($status) {
            'draft' => '草稿',
            'approved' => '已换货',
            'cancelled' => '已取消',
            default => $status,
        };
    }

    private function settleLabel(string $method): string
    {
        return match ($method) {
            'cash' => '现金',
            'offset' => '冲抵应收',
            'credit' => '挂账',
            default => $method,
        };
    }

    /** 详情 */
    public function show($id)
    {
        $order = VanExchangeOrder::with(['items.productOut', 'items.productIn', 'customer', 'vehicle'])->find($id);
        if (! $order) {
            return $this->notFound('换货单不存在');
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
            'exchange_date' => 'nullable|date',
            'settle_method' => 'nullable|in:cash,offset,credit',
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

        return DB::transaction(function () use ($validated, $adminId, $adminName) {
            $vwId = (int) $validated['vehicle_warehouse_id'];
            $customerId = (int) $validated['customer_id'];

            $allProductIds = collect($validated['items'])
                ->flatMap(fn ($i) => [(int) $i['product_id_out'], (int) $i['product_id_in']])
                ->unique();
            $productMap = DB::table('products')->whereIn('id', $allProductIds)->get()->keyBy('id');

            $amountOutTotal = 0.0;
            $amountInTotal = 0.0;
            $itemRows = [];
            $sort = 0;

            foreach ($validated['items'] as $item) {
                $productOut = $productMap->get((int) $item['product_id_out']);
                $productIn = $productMap->get((int) $item['product_id_in']);
                if (! $productOut || ! $productIn) {
                    continue;
                }
                $qty = (int) $item['qty'];
                $unitPriceOut = (float) ($item['unit_price_out'] ?? $productOut->price_small ?? 0);
                $unitPriceIn = (float) ($item['unit_price_in'] ?? $productIn->price_small ?? 0);
                $amountOut = round($qty * $unitPriceOut, 2);
                $amountIn = round($qty * $unitPriceIn, 2);
                $diff = round($amountIn - $amountOut, 2);
                $amountOutTotal += $amountOut;
                $amountInTotal += $amountIn;

                $itemRows[] = [
                    'product_id_out' => (int) $item['product_id_out'],
                    'product_name_out' => $productOut->name,
                    'spec_out' => $productOut->spec,
                    'qty' => $qty,
                    'unit_price_out' => $unitPriceOut,
                    'amount_out' => $amountOut,
                    'product_id_in' => (int) $item['product_id_in'],
                    'product_name_in' => $productIn->name,
                    'spec_in' => $productIn->spec,
                    'unit_price_in' => $unitPriceIn,
                    'amount_in' => $amountIn,
                    'diff_amount' => $diff,
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

            $diffAmount = round($amountInTotal - $amountOutTotal, 2);
            $order = VanExchangeOrder::create([
                'exchange_no' => $this->generateNo('VHD', 'van_exchange_orders', 'exchange_no'),
                'salesman_id' => $adminId,
                'salesman_name' => $adminName,
                'customer_id' => $customerId,
                'customer_name' => DB::table('customers')->where('id', $customerId)->value('name'),
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'vehicle_warehouse_id' => $vwId,
                'exchange_date' => $validated['exchange_date'] ?? now()->toDateString(),
                'diff_amount' => $diffAmount,
                'settle_method' => $validated['settle_method'] ?? 'cash',
                'status' => VanExchangeOrder::STATUS_DRAFT,
                'created_by' => $adminId,
                'creator_name' => $adminName,
                'remark' => $validated['remark'] ?? null,
            ]);

            foreach ($itemRows as &$row) {
                $row['order_id'] = $order->id;
            }
            unset($row);
            VanExchangeOrderItem::insert($itemRows);

            return $this->created($order->load('items', 'customer', 'vehicle'), '草稿已保存');
        });
    }

    /** 更新草稿 */
    public function update(Request $request, $id)
    {
        $order = VanExchangeOrder::find($id);
        if (! $order) {
            return $this->notFound('换货单不存在');
        }
        if ($order->status !== VanExchangeOrder::STATUS_DRAFT) {
            return $this->error('当前状态不能修改', 422);
        }

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'exchange_date' => 'nullable|date',
            'settle_method' => 'nullable|in:cash,offset,credit',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id_out' => 'required|exists:products,id',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.unit_price_out' => 'nullable|numeric|min:0',
            'items.*.product_id_in' => 'required|exists:products,id',
            'items.*.unit_price_in' => 'nullable|numeric|min:0',
            'items.*.remark' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($order, $validated) {
            $customerId = (int) $validated['customer_id'];
            $allProductIds = collect($validated['items'])
                ->flatMap(fn ($i) => [(int) $i['product_id_out'], (int) $i['product_id_in']])
                ->unique();
            $productMap = DB::table('products')->whereIn('id', $allProductIds)->get()->keyBy('id');

            $amountOutTotal = 0.0;
            $amountInTotal = 0.0;
            $itemRows = [];
            $sort = 0;

            foreach ($validated['items'] as $item) {
                $productOut = $productMap->get((int) $item['product_id_out']);
                $productIn = $productMap->get((int) $item['product_id_in']);
                if (! $productOut || ! $productIn) {
                    continue;
                }
                $qty = (int) $item['qty'];
                $unitPriceOut = (float) ($item['unit_price_out'] ?? $productOut->price_small ?? 0);
                $unitPriceIn = (float) ($item['unit_price_in'] ?? $productIn->price_small ?? 0);
                $amountOut = round($qty * $unitPriceOut, 2);
                $amountIn = round($qty * $unitPriceIn, 2);
                $amountOutTotal += $amountOut;
                $amountInTotal += $amountIn;

                $itemRows[] = [
                    'order_id' => $order->id,
                    'product_id_out' => (int) $item['product_id_out'],
                    'product_name_out' => $productOut->name,
                    'spec_out' => $productOut->spec,
                    'qty' => $qty,
                    'unit_price_out' => $unitPriceOut,
                    'amount_out' => $amountOut,
                    'product_id_in' => (int) $item['product_id_in'],
                    'product_name_in' => $productIn->name,
                    'spec_in' => $productIn->spec,
                    'unit_price_in' => $unitPriceIn,
                    'amount_in' => $amountIn,
                    'diff_amount' => round($amountIn - $amountOut, 2),
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
            VanExchangeOrderItem::insert($itemRows);
            $order->update([
                'customer_id' => $customerId,
                'customer_name' => DB::table('customers')->where('id', $customerId)->value('name'),
                'exchange_date' => $validated['exchange_date'] ?? $order->exchange_date?->toDateString() ?? now()->toDateString(),
                'diff_amount' => round($amountInTotal - $amountOutTotal, 2),
                'settle_method' => $validated['settle_method'] ?? $order->settle_method,
                'remark' => array_key_exists('remark', $validated) ? $validated['remark'] : $order->remark,
            ]);

            return $this->success($order->load('items', 'customer', 'vehicle'), '草稿已更新');
        });
    }

    /** 删除（仅 draft） */
    public function destroy($id)
    {
        $order = VanExchangeOrder::find($id);
        if (! $order) {
            return $this->notFound('换货单不存在');
        }
        if ($order->status !== VanExchangeOrder::STATUS_DRAFT) {
            return $this->error('当前状态不能删除', 422);
        }

        $order->items()->delete();
        $order->delete();

        return $this->success(null, '已删除');
    }

    /**
     * 确认换货（draft → approved）：事务内 换出入车库存 + 换入扣车库存 + 差价结算。
     * diff_amount > 0 客户补款；< 0 退给客户；= 0 不结算。
     */
    public function approve($id)
    {
        $order = VanExchangeOrder::with('items')->find($id);
        if (! $order) {
            return $this->notFound('换货单不存在');
        }
        if ($order->status === VanExchangeOrder::STATUS_APPROVED) {
            return $this->success($order, '已确认，无需重复操作');
        }
        if ($order->status !== VanExchangeOrder::STATUS_DRAFT) {
            return $this->error('只有草稿状态的换货单才能确认', 422);
        }

        [$adminId, $adminName] = $this->currentAdmin();

        try {
            DB::transaction(function () use ($order, $adminId, $adminName) {
                foreach ($order->items as $item) {
                    $qty = (int) $item->qty;
                    if ($qty <= 0) {
                        continue;
                    }
                    // 换出商品退回车上库存
                    $this->stockService->stockIn(
                        (int) $item->product_id_out,
                        (int) $order->vehicle_warehouse_id,
                        $qty,
                        null,
                        (int) $order->id,
                        'VanExchangeOrder'
                    );
                    // 换入商品从车上库存扣减
                    $this->stockService->stockOut(
                        (int) $item->product_id_in,
                        (int) $order->vehicle_warehouse_id,
                        $qty,
                        (int) $order->id,
                        'VanExchangeOrder',
                        '车销换入出库'
                    );
                }

                $diff = (float) $order->diff_amount;
                $exchangeDate = $order->exchange_date?->toDateString() ?? now()->toDateString();

                if ($diff > 0.01) {
                    // 差价>0：客户补款
                    if ($order->settle_method === VanExchangeOrder::SETTLE_CASH) {
                        $this->writeCashFlow('receive', $diff, $order->id, 'VanExchangeOrder', $exchangeDate, '车销换货补款-'.$order->exchange_no, $adminId);
                    } else {
                        // 冲抵应收/挂账：增加客户应收
                        DB::table('customers')->where('id', $order->customer_id)->increment('balance', $diff);
                    }
                } elseif ($diff < -0.01) {
                    // 差价<0：退给客户
                    if ($order->settle_method === VanExchangeOrder::SETTLE_CASH) {
                        $this->writeCashFlow('pay', $diff, $order->id, 'VanExchangeOrder', $exchangeDate, '车销换货退款-'.$order->exchange_no, $adminId);
                    } else {
                        // 冲抵应收/挂账：减少客户应收
                        DB::table('customers')->where('id', $order->customer_id)->decrement('balance', abs($diff));
                    }
                }

                $order->update(['status' => VanExchangeOrder::STATUS_APPROVED, 'approved_at' => now()]);
                $this->writeOperationLog($order, $adminId, $adminName, 'approve', '换货确认', VanExchangeOrder::STATUS_DRAFT, VanExchangeOrder::STATUS_APPROVED, '差价¥'.number_format($diff, 2));
            });
        } catch (StockRuleException $e) {
            return $this->error('确认换货失败：'.$e->getMessage(), 422);
        }

        return $this->success($order->load('items', 'customer', 'vehicle'), '换货已确认，库存已调整');
    }

    /** 取消（仅 draft） */
    public function cancel($id)
    {
        $order = VanExchangeOrder::find($id);
        if (! $order) {
            return $this->notFound('换货单不存在');
        }
        if ($order->status !== VanExchangeOrder::STATUS_DRAFT) {
            return $this->error('当前状态不能取消', 422);
        }

        $order->update(['status' => VanExchangeOrder::STATUS_CANCELLED]);

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

    private function writeOperationLog(VanExchangeOrder $order, ?int $adminId, string $adminName, string $action, string $actionLabel, ?string $fromStatus, ?string $toStatus, ?string $detail = null): void
    {
        DB::table('order_operation_logs')->insert([
            'order_id' => $order->id,
            'order_no' => $order->exchange_no,
            'order_type' => 'van_exchange_order',
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
