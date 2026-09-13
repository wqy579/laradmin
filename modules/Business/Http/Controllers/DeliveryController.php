<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Business\Models\Delivery;
use Modules\Business\Models\DeliveryItem;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryController extends Controller
{
    use ResponseTrait;

    public function index(Request $request)
    {
        $query = Delivery::with(['customer', 'warehouse', 'vehicle', 'driver', 'route']);

        if ($request->has('keyword') && $request->keyword) {
            $query->where(function ($q) use ($request) {
                $q->where('delivery_no', 'like', '%' . $request->keyword . '%')
                  ->orWhereHas('customer', function ($q) use ($request) {
                      $q->where('name', 'like', '%' . $request->keyword . '%');
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

        if (!$delivery) {
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
            $deliveryNo = 'DEL' . date('YmdHis') . strtoupper(substr(md5(time()), 0, 4));
            
            $delivery = Delivery::create([
                'delivery_no' => $deliveryNo,
                'order_id' => $validated['order_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'vehicle_id' => $validated['vehicle_id'],
                'driver_id' => $validated['driver_id'],
                'route_id' => $validated['route_id'],
                'customer_id' => $validated['customer_id'],
                'delivery_date' => $validated['delivery_date'] ?? date('Y-m-d'),
                'status' => 0,
                'total_amount' => 0,
                'remark' => $validated['remark'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                $product = \Modules\Business\Models\Product::find($item['product_id']);
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
        if (!$delivery) {
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
                $product = \Modules\Business\Models\Product::find($item['product_id']);
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
        if (!$delivery) {
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
        $delivery = Delivery::find($id);
        if (!$delivery) {
            return $this->notFound('发货单不存在');
        }

        if ($delivery->status != 0) {
            return $this->error('只有待发货状态的单据才能发货');
        }

        $delivery->status = 1;
        $delivery->save();

        return $this->success(null, '发货成功');
    }

    public function complete($id)
    {
        $delivery = Delivery::find($id);
        if (!$delivery) {
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
