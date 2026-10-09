<?php

namespace Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Delivery\Models\DeliveryPicking;
use Modules\Delivery\Models\DeliveryPickingItem;
use Modules\Delivery\Services\DeliveryFlowService;
use Modules\Stock\Exceptions\StockRuleException;
use Modules\Stock\Services\StockService;

/**
 * 配货单管理（文员配货）。
 *
 * 从「已审核」销售订单创建配货单，确认配货后冻结库存并自动生成拣货单，
 * 订单状态推进为「配货中」。
 */
class PickingController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private StockService $stocks,
        private DeliveryFlowService $flow,
    ) {}

    /** 配货单列表（分页） */
    public function index(Request $request)
    {
        $query = DeliveryPicking::query()->with(['items', 'customer']);

        if ($no = $request->input('picking_no')) {
            $query->where('picking_no', 'like', "%{$no}%");
        }
        if ($orderNo = $request->input('order_no')) {
            $query->where('order_no', 'like', "%{$orderNo}%");
        }
        if ($customerId = $request->input('customer_id')) {
            $query->where('customer_id', $customerId);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($range = $request->input('date_range')) {
            [$start, $end] = is_array($range) ? $range : explode(',', (string) $range);
            if ($start ?? null) {
                $query->where('picking_date', '>=', $start);
            }
            if ($end ?? null) {
                $query->where('picking_date', '<=', $end);
            }
        }

        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(200, max(10, (int) $request->input('page_size', 20)));

        return $this->paginated($query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page));
    }

    /** 配货单详情 */
    public function show(int $id)
    {
        $picking = DeliveryPicking::with(['items', 'customer'])->find($id);
        if (! $picking) {
            return $this->error('配货单不存在', 404);
        }

        return $this->success($picking);
    }

    /** 待配货订单列表（已审核可配货的订单，带商品明细+当前可用库存） */
    public function pendingOrders(Request $request)
    {
        // 已审核 = status 为 pending（待配货）。sales_orders 创建后默认 pending。
        $orders = DB::table('sales_orders as o')
            ->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'o.warehouse_id')
            ->where('o.status', 'pending')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('delivery_picking')
                    ->whereColumn('delivery_picking.sales_order_id', 'o.id')
                    ->whereIn('delivery_picking.status', ['pending', 'picked']);
            })
            ->orderByDesc('o.id')
            ->limit(100)
            ->get([
                'o.id', 'o.order_no', 'o.customer_id', 'c.name as customer_name',
                'o.warehouse_id', 'w.name as warehouse_name',
                'o.total_amount', 'o.total_qty', 'o.order_date', 'o.status',
            ]);

        // 附带商品明细
        $orderIds = $orders->pluck('id')->all();
        $items = $orderIds ? DB::table('sales_order_items as si')
            ->leftJoin('products as p', 'p.id', '=', 'si.product_id')
            ->whereIn('si.sales_order_id', $orderIds)
            ->orderBy('si.id')
            ->get([
                'si.id as item_id', 'si.sales_order_id', 'si.product_id',
                'p.code as product_code', 'p.name as product_name', 'p.spec',
                'p.price_unit_small as unit',
                'si.quantity as order_qty', 'si.qty_large', 'si.qty_medium', 'si.qty_small',
                'si.price', 'si.amount',
            ])->groupBy('sales_order_id') : [];

        return $this->success(['orders' => $orders, 'items' => $items]);
    }

    /** 新增配货单（选择订单 + 配货数量，初始状态 pending 待配货） */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'sales_order_id' => 'required|integer|exists:sales_orders,id',
            'picking_date' => 'nullable|date',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.product_id' => 'nullable|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'nullable|numeric',
            'items.*.amount' => 'nullable|numeric',
            'remark' => 'nullable|string',
        ]);

        $order = DB::table('sales_orders')->where('id', $validated['sales_order_id'])->first();
        if (! $order) {
            return $this->error('订单不存在', 404);
        }
        if ($order->status !== 'pending') {
            return $this->error('只能对「待配货」状态的订单创建配货单', 422);
        }
        // 已存在未完成配货单
        $exists = DeliveryPicking::where('sales_order_id', $order->id)
            ->whereIn('status', [DeliveryPicking::STATUS_PENDING, DeliveryPicking::STATUS_PICKED])
            ->exists();
        if ($exists) {
            return $this->error('该订单已存在未完成的配货单', 422);
        }

        $customer = DB::table('customers')->where('id', $order->customer_id)->first();
        $admin = auth('admin')->user();

        $pickingNo = $this->generateNo('PH', 'delivery_picking', 'picking_no');
        $pickingDate = $validated['picking_date'] ?? now()->toDateString();

        DB::beginTransaction();
        try {
            $picking = DeliveryPicking::create([
                'picking_no' => $pickingNo,
                'sales_order_id' => $order->id,
                'order_no' => $order->order_no,
                'customer_id' => $order->customer_id,
                'customer_name' => $customer?->name,
                'warehouse_id' => $order->warehouse_id,
                'picking_date' => $pickingDate,
                'status' => DeliveryPicking::STATUS_PENDING,
                'created_by' => $admin?->id,
                'creator_name' => $admin?->username,
            ]);

            $totalSkus = 0;
            $totalQty = 0;
            $totalAmount = 0;
            foreach ($validated['items'] as $i => $row) {
                // 校验配货数量不超过订单数量
                $orderItem = DB::table('sales_order_items')->where('id', $row['item_id'])->first();
                if (! $orderItem) {
                    throw new \Exception('订单明细不存在');
                }
                if ((int) $row['quantity'] > (int) $orderItem->quantity) {
                    throw new \Exception('配货数量不能超过订单数量');
                }

                $product = $row['product_id'] ? DB::table('products')->where('id', $row['product_id'])->first() : null;
                DeliveryPickingItem::create([
                    'picking_id' => $picking->id,
                    'sales_order_item_id' => $row['item_id'],
                    'product_id' => $row['product_id'] ?? $orderItem->product_id,
                    'product_code' => $product?->code,
                    'product_name' => $product?->name,
                    'spec' => $product?->spec,
                    'unit' => $product?->price_unit_small,
                    'order_qty' => (int) $orderItem->quantity,
                    'qty_large' => $orderItem->qty_large ?? 0,
                    'qty_medium' => $orderItem->qty_medium ?? 0,
                    'qty_small' => $orderItem->qty_small ?? 0,
                    'quantity' => (int) $row['quantity'],
                    'price' => $row['price'] ?? $orderItem->price,
                    'amount' => $row['amount'] ?? round((float) ($row['price'] ?? $orderItem->price) * (int) $row['quantity'], 2),
                    'sort' => $i,
                ]);
                $totalSkus++;
                $totalQty += (int) $row['quantity'];
                $totalAmount += (float) ($row['amount'] ?? round((float) ($row['price'] ?? $orderItem->price) * (int) $row['quantity'], 2));
            }

            $picking->update([
                'total_skus' => $totalSkus,
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
            ]);

            DB::commit();

            return $this->created($picking->fresh(['items']), '配货单已创建，请确认配货');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        }
    }

    /** 更新配货单（仅待配货状态可改） */
    public function update(Request $request, int $id)
    {
        $picking = DeliveryPicking::find($id);
        if (! $picking) {
            return $this->error('配货单不存在', 404);
        }
        if ($picking->status !== DeliveryPicking::STATUS_PENDING) {
            return $this->error('当前状态不可编辑', 422);
        }

        $validated = $request->validate([
            'picking_date' => 'nullable|date',
            'items' => 'sometimes|array',
            'items.*.id' => 'nullable|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'nullable|numeric',
            'items.*.amount' => 'nullable|numeric',
        ]);

        DB::beginTransaction();
        try {
            if (isset($validated['picking_date'])) {
                $picking->picking_date = $validated['picking_date'];
            }
            if (isset($validated['items'])) {
                $totalSkus = 0;
                $totalQty = 0;
                $totalAmount = 0;
                foreach ($validated['items'] as $i => $row) {
                    $orderItem = DB::table('sales_order_items')->where('id', $picking->items[$i]['sales_order_item_id'] ?? null)->first();
                    if ($orderItem && (int) $row['quantity'] > (int) $orderItem->quantity) {
                        throw new \Exception('配货数量不能超过订单数量');
                    }
                    $update = ['quantity' => (int) $row['quantity']];
                    if (isset($row['price'])) {
                        $update['price'] = $row['price'];
                    }
                    $update['amount'] = $row['amount'] ?? round((float) ($row['price'] ?? $picking->items[$i]['price'] ?? 0) * (int) $row['quantity'], 2);
                    if ($row['id'] ?? null) {
                        DeliveryPickingItem::where('id', $row['id'])->update($update);
                    }
                    $totalSkus++;
                    $totalQty += (int) $row['quantity'];
                    $totalAmount += (float) $update['amount'];
                }
                $picking->total_skus = $totalSkus;
                $picking->total_qty = $totalQty;
                $picking->total_amount = round($totalAmount, 2);
            }
            $picking->save();
            DB::commit();

            return $this->success($picking->fresh(['items']), '更新成功');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        }
    }

    /** 确认配货：冻结库存 + 自动生成拣货单 + 订单状态推进「配货中」 */
    public function confirm(Request $request, int $id)
    {
        $picking = DeliveryPicking::with('items')->find($id);
        if (! $picking) {
            return $this->error('配货单不存在', 404);
        }
        if ($picking->status !== DeliveryPicking::STATUS_PENDING) {
            return $this->error('当前状态不可确认配货', 422);
        }

        $admin = auth('admin')->user();

        DB::beginTransaction();
        try {
            // 1. 冻结库存（若已冻结则跳过）
            if (! $picking->stock_frozen) {
                foreach ($picking->items as $item) {
                    $this->stocks->freeze(
                        (int) $item->product_id,
                        (int) $picking->warehouse_id,
                        (int) $item->quantity,
                        (int) $picking->sales_order_id // relatedId 用订单ID，与销售冻结流水一致
                    );
                }
                $picking->stock_frozen = true;
            }

            // 2. 自动生成拣货单
            $pick = $this->flow->createPickFromPicking($picking);

            $picking->status = DeliveryPicking::STATUS_PICKED;
            $picking->pick_id = $pick->id;
            $picking->confirmed_at = now();
            $picking->save();

            // 3. 订单状态推进为「配货中」（直接DB update，不调SalesOrderController::approve避免副作用）
            DB::table('sales_orders')->where('id', $picking->sales_order_id)->update([
                'status' => '配货中',
            ]);
            $this->logOrderOperation($picking->sales_order_id, $picking->order_no, '配货', '配货单 '.$picking->picking_no.' 确认配货', 'pending', '配货中');

            DB::commit();

            return $this->success($picking->fresh(['items']), '配货确认成功，已生成拣货单');
        } catch (StockRuleException $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        }
    }

    /** 取消配货单（待配货/已配货可取消，已冻结则解冻） */
    public function cancel(Request $request, int $id)
    {
        $picking = DeliveryPicking::find($id);
        if (! $picking) {
            return $this->error('配货单不存在', 404);
        }
        if (! in_array($picking->status, [DeliveryPicking::STATUS_PENDING, DeliveryPicking::STATUS_PICKED], true)) {
            return $this->error('当前状态不可取消', 422);
        }
        if ($picking->pick_id) {
            // 已生成拣货单，若拣货单已拣货则不允许取消
            $pickExists = DB::table('delivery_pick')
                ->where('picking_id', $picking->id)
                ->whereIn('status', ['picking', 'picked'])
                ->exists();
            if ($pickExists) {
                return $this->error('拣货单已开始拣货，不可取消配货单', 422);
            }
        }

        DB::beginTransaction();
        try {
            // 解冻已冻结的库存
            if ($picking->stock_frozen) {
                foreach ($picking->items as $item) {
                    $this->stocks->unfreeze(
                        (int) $item->product_id,
                        (int) $picking->warehouse_id,
                        (int) $item->quantity,
                        (int) $picking->sales_order_id
                    );
                }
                $picking->stock_frozen = false;
            }

            $picking->status = DeliveryPicking::STATUS_CANCELLED;
            $picking->save();

            // 取消拣货单
            if ($picking->pick_id) {
                DB::table('delivery_pick')->where('id', $picking->pick_id)->update(['status' => 'cancelled']);
            }

            DB::commit();

            return $this->success($picking, '配货单已取消');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        }
    }

    /** 删除配货单（仅待配货） */
    public function destroy(int $id)
    {
        $picking = DeliveryPicking::find($id);
        if (! $picking) {
            return $this->error('配货单不存在', 404);
        }
        if ($picking->status !== DeliveryPicking::STATUS_PENDING) {
            return $this->error('仅待配货状态可删除', 422);
        }
        DB::beginTransaction();
        try {
            $picking->items()->delete();
            $picking->delete();
            DB::commit();

            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        }
    }

    /** 单号生成：前缀+Ymd+6位流水（参考 StockAdjustController::generateNo） */
    private function generateNo(string $prefix, string $table, string $column): string
    {
        $like = $prefix.date('Ymd').'%';
        $last = DB::table($table)->where($column, 'like', $like)->orderByDesc($column)->value($column);
        $seq = $last ? intval(substr($last, -6)) + 1 : 1;

        return $prefix.date('Ymd').str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    /** 订单操作日志 */
    private function logOrderOperation(int $orderId, string $orderNo, string $action, string $detail, ?string $from, ?string $to): void
    {
        $admin = auth('admin')->user();
        DB::table('order_operation_logs')->insert([
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'order_type' => 'sales_order',
            'user_id' => $admin?->id,
            'user_name' => $admin?->username,
            'operator_id' => $admin?->id,
            'operator_name' => $admin?->username,
            'action' => $action,
            'action_label' => $action,
            'detail' => mb_substr($detail, 0, 500),
            'remark' => mb_substr($detail, 0, 500),
            'from_status' => $from,
            'to_status' => $to,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
