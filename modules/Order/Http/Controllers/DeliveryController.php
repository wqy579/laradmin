<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Delivery;
use Modules\Order\Models\DeliveryItem;
use Modules\Stock\Exceptions\StockRuleException;
use Modules\Stock\Models\Product;
use Modules\Stock\Services\StockService;

class DeliveryController extends Controller
{
    use ResponseTrait;

    public function __construct(private readonly StockService $stocks) {}

    public function index(Request $request)
    {
        $query = Delivery::with(['customer', 'warehouse', 'vehicle', 'driver', 'route']);

        if ($request->has('keyword') && $request->keyword) {
            $query->where(function ($q) use ($request) {
                $q->where('delivery_no', 'like', '%'.$request->keyword.'%')
                    ->orWhereHas('customer', function ($q) use ($request) {
                        $q->where('name', 'like', '%'.$request->keyword.'%');
                    });
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('date_start')) {
            $query->whereDate('delivery_date', '>=', $request->date_start);
        }

        if ($request->has('date_end')) {
            $query->whereDate('delivery_date', '<=', $request->date_end);
        }

        $deliveries = $query->orderByDesc('created_at')->paginate($request->input('per_page', 15));

        return $this->success($deliveries);
    }

    public function show($id)
    {
        $delivery = Delivery::with(['customer', 'warehouse', 'vehicle', 'driver', 'route', 'items.product'])
            ->find($id);

        if (! $delivery) {
            return $this->notFound('发货单不存在');
        }

        return $this->success($delivery);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'nullable|exists:sales_orders,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'driver_id' => 'nullable|exists:employees,id',
            'route_id' => 'nullable|exists:routes,id',
            'customer_id' => 'required|exists:customers,id',
            'delivery_date' => 'nullable|date',
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'remark' => 'nullable|string',
        ]);

        try {
            $deliveryNo = 'DEL'.date('YmdHis').strtoupper(substr(md5(time()), 0, 4));

            $delivery = Delivery::create([
                'delivery_no' => $deliveryNo,
                'order_id' => $validated['order_id'] ?? null,
                'warehouse_id' => $validated['warehouse_id'],
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'driver_id' => $validated['driver_id'] ?? null,
                'route_id' => $validated['route_id'] ?? null,
                'customer_id' => $validated['customer_id'],
                'delivery_date' => $validated['delivery_date'] ?? date('Y-m-d'),
                'status' => 0,
                'total_amount' => 0,
                'remark' => $validated['remark'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                $product = Product::find($item['product_id']);
                DeliveryItem::create([
                    'delivery_id' => $delivery->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->price ?? 0,
                    'amount' => $item['quantity'] * ($product->price ?? 0),
                ]);
            }

            $delivery->refresh();
            $delivery->total_amount = $delivery->items->sum('amount');
            $delivery->save();

            return $this->success($delivery, '创建成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function update(Request $request, $id)
    {
        $delivery = Delivery::find($id);
        if (! $delivery) {
            return $this->notFound('发货单不存在');
        }

        if ($delivery->status > 0) {
            return $this->error('已发货的单据不能修改');
        }

        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'driver_id' => 'nullable|exists:employees,id',
            'route_id' => 'nullable|exists:routes,id',
            'customer_id' => 'required|exists:customers,id',
            'delivery_date' => 'nullable|date',
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'remark' => 'nullable|string',
        ]);

        try {
            $delivery->update([
                'warehouse_id' => $validated['warehouse_id'],
                'vehicle_id' => $validated['vehicle_id'],
                'driver_id' => $validated['driver_id'],
                'route_id' => $validated['route_id'],
                'customer_id' => $validated['customer_id'],
                'delivery_date' => $validated['delivery_date'] ?? $delivery->delivery_date,
                'remark' => $validated['remark'] ?? $delivery->remark,
            ]);

            DB::table('delivery_items')->where('delivery_id', $id)->delete();

            foreach ($validated['items'] as $item) {
                $product = Product::find($item['product_id']);
                DeliveryItem::create([
                    'delivery_id' => $id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->price ?? 0,
                    'amount' => $item['quantity'] * ($product->price ?? 0),
                ]);
            }

            $delivery->refresh();
            $delivery->total_amount = $delivery->items->sum('amount');
            $delivery->save();

            return $this->success($delivery->fresh(), '更新成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function destroy($id)
    {
        $delivery = Delivery::find($id);
        if (! $delivery) {
            return $this->notFound('发货单不存在');
        }

        if ($delivery->status > 0) {
            return $this->error('已发货的单据不能删除');
        }

        $delivery->delete();

        return $this->success(null, '删除成功');
    }

    public function dispatch($id)
    {
        $delivery = Delivery::with('items')->find($id);
        if (! $delivery) {
            return $this->notFound('发货单不存在');
        }

        if ($delivery->status != 0) {
            return $this->error('只有待发货状态的单据才能发货');
        }

        // 发货即出库：货物一旦发出，对应明细要从所属仓库扣减。
        // 此前只翻状态不扣库存，导致销售链路库存只进不出、账面持续虚高。
        // 放在 dispatch 而非 complete——「已发出」就是货物离仓的时点，
        // 与采购侧「receive 即入库」对称；complete 只做签收确认，不再动库存。
        DB::beginTransaction();
        try {
            foreach ($delivery->items as $item) {
                $this->stocks->stockOut(
                    (int) $item->product_id,
                    (int) $delivery->warehouse_id,
                    (int) $item->quantity,
                );
            }
            $delivery->status = 1;
            $delivery->save();
            DB::commit();

            return $this->success(null, '发货成功');
        } catch (StockRuleException $e) {
            DB::rollBack();
            // 库存不足是业务拒绝，不是服务器错误，按 422 返回
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error('发货失败：'.$e->getMessage(), 500);
        }
    }

    public function complete($id)
    {
        $delivery = Delivery::find($id);
        if (! $delivery) {
            return $this->notFound('发货单不存在');
        }

        if ($delivery->status != 1) {
            return $this->error('只有已发货状态的单据才能完成');
        }

        $delivery->status = 2;
        $delivery->save();

        return $this->success(null, '完成成功');
    }

    public function statistics()
    {
        $stats = [
            'total' => Delivery::count(),
            'pending' => Delivery::where('status', 0)->count(),
            'dispatched' => Delivery::where('status', 1)->count(),
            'completed' => Delivery::where('status', 2)->count(),
            'cancelled' => Delivery::where('status', 3)->count(),
            'total_amount' => Delivery::sum('total_amount'),
            'paid_amount' => Delivery::sum('paid_amount'),
        ];

        return $this->success($stats);
    }
}
