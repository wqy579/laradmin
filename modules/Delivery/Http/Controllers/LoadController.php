<?php

namespace Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Delivery\Models\DeliveryCheck;
use Modules\Delivery\Models\DeliveryLoad;
use Modules\Delivery\Models\DeliveryLoadItem;
use Modules\Delivery\Services\DeliveryFlowService;
use Modules\Stock\Exceptions\StockRuleException;
use Modules\Stock\Services\StockService;

/**
 * 装车单管理（库管装车）。选择多个已验货单+配送员+车牌，
 * 确认装车时扣减库存(stockOut+unfreeze)并生成配送任务。
 */
class LoadController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private StockService $stocks,
        private DeliveryFlowService $flow,
    ) {}

    public function index(Request $request)
    {
        $query = DeliveryLoad::query()->with('items');

        if ($no = $request->input('load_no')) {
            $query->where('load_no', 'like', "%{$no}%");
        }
        if ($person = $request->input('delivery_person_name')) {
            $query->where('delivery_person_name', 'like', "%{$person}%");
        }
        if ($plate = $request->input('plate_no')) {
            $query->where('plate_no', 'like', "%{$plate}%");
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($range = $request->input('date_range')) {
            [$start, $end] = is_array($range) ? $range : explode(',', (string) $range);
            if ($start ?? null) {
                $query->where('load_date', '>=', $start);
            }
            if ($end ?? null) {
                $query->where('load_date', '<=', $end);
            }
        }

        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(200, max(10, (int) $request->input('page_size', 20)));

        return $this->paginated($query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page));
    }

    public function show(int $id)
    {
        $load = DeliveryLoad::with('items')->find($id);
        if (! $load) {
            return $this->error('装车单不存在', 404);
        }

        return $this->success($load);
    }

    /** 新增装车单（选择验货单+配送员+车牌，初始状态 pending 待装车） */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'delivery_person_id' => 'required|integer',
            'employee_id' => 'nullable|integer',
            'vehicle_id' => 'nullable|integer',
            'plate_no' => 'nullable|string|max:30',
            'load_date' => 'nullable|date',
            'check_ids' => 'required|array|min:1',
            'check_ids.*' => 'required|integer',
            'remark' => 'nullable|string',
        ]);

        $admin = auth('admin')->user();

        // 校验配送员
        $person = DB::table('auth_user')->where('id', $validated['delivery_person_id'])->first();
        if (! $person) {
            return $this->error('配送员不存在', 422);
        }
        $employeeId = $validated['employee_id'] ?? null;
        if (! $employeeId) {
            $emp = DB::table('employees')->where('user_id', $person->id)->first();
            $employeeId = $emp?->id;
        }
        $vehicle = null;
        if ($validated['vehicle_id'] ?? null) {
            $vehicle = DB::table('vehicles')->where('id', $validated['vehicle_id'])->first();
        }

        // 校验验货单状态
        $checks = DeliveryCheck::with('items')->whereIn('id', $validated['check_ids'])
            ->where('status', DeliveryCheck::STATUS_CHECKED)->get();
        if ($checks->count() !== count($validated['check_ids'])) {
            return $this->error('所选验货单必须全部为「已验货」状态', 422);
        }
        // 验货单未被其他装车单引用
        $usedIds = DeliveryLoadItem::whereIn('check_id', $validated['check_ids'])->exists();
        if ($usedIds) {
            return $this->error('存在已被其他装车单引用的验货单', 422);
        }

        $loadNo = $this->generateNo('LD', 'delivery_load', 'load_no');
        $loadDate = $validated['load_date'] ?? now()->toDateString();

        DB::beginTransaction();
        try {
            $load = DeliveryLoad::create([
                'load_no' => $loadNo,
                'load_date' => $loadDate,
                'delivery_person_id' => $person->id,
                'delivery_person_name' => $person->username,
                'employee_id' => $employeeId,
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'plate_no' => $validated['plate_no'] ?? $vehicle?->plate_no,
                'status' => DeliveryLoad::STATUS_PENDING,
                'created_by' => $admin?->id,
                'creator_name' => $admin?->username,
                'remark' => $validated['remark'] ?? null,
            ]);

            $orderCount = 0;
            $totalSkus = 0;
            $totalQty = 0;
            $totalAmount = 0;
            $sort = 0;
            foreach ($checks as $check) {
                $orderCount++;
                // 取客户信息
                $customer = $check->customer_id ? DB::table('customers')->where('id', $check->customer_id)->first() : null;
                // 回溯配货单（用于装车出库时找 warehouse_id 和 picking_id）
                $pickingId = DB::table('delivery_pick')->where('id', $check->pick_id)->value('picking_id');
                $warehouseId = DB::table('delivery_picking')->where('id', $pickingId)->value('warehouse_id');
                foreach ($check->items as $item) {
                    // 取价格（从拣货→配货链路取）
                    $pickingItem = DB::table('delivery_picking_items')
                        ->where('id', $item->pick_item_id ?? null)->first();
                    $price = $pickingItem?->price ?? 0;
                    $amount = round($price * $item->actual_qty, 2);
                    DeliveryLoadItem::create([
                        'load_id' => $load->id,
                        'check_id' => $check->id,
                        'check_no' => $check->check_no,
                        'sales_order_id' => $check->sales_order_id ?? null,
                        'order_no' => null,
                        'customer_id' => $check->customer_id,
                        'customer_name' => $check->customer_name ?? $customer?->name,
                        'address' => $customer?->address,
                        'phone' => $customer?->phone,
                        'product_id' => $item->product_id,
                        'product_code' => $item->product_code,
                        'product_name' => $item->product_name,
                        'spec' => $item->spec,
                        'unit' => $item->unit,
                        'quantity' => $item->actual_qty,
                        'price' => $price,
                        'amount' => $amount,
                        'picking_id' => $pickingId,
                        'sort' => $sort++,
                    ]);
                    $totalSkus++;
                    $totalQty += $item->actual_qty;
                    $totalAmount += $amount;
                }
            }
            // 取订单号
            $load->items()->each(function ($item) {
                $orderNo = $item->sales_order_id ? DB::table('sales_orders')->where('id', $item->sales_order_id)->value('order_no') : null;
                if ($orderNo) {
                    $item->update(['order_no' => $orderNo]);
                }
            });

            $load->update([
                'order_count' => $orderCount,
                'total_skus' => $totalSkus,
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
            ]);

            DB::commit();

            return $this->created($load->fresh(['items']), '装车单已创建，请确认装车');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        }
    }

    /** 确认装车：扣减库存(stockOut + unfreeze) + 生成配送任务 + 状态配送中 */
    public function confirm(Request $request, int $id)
    {
        $load = DeliveryLoad::with('items')->find($id);
        if (! $load) {
            return $this->error('装车单不存在', 404);
        }
        if ($load->status !== DeliveryLoad::STATUS_PENDING) {
            return $this->error('当前状态不可确认装车', 422);
        }

        DB::beginTransaction();
        try {
            // 按配货单分组扣减库存（同一 picking 一起处理，便于 unfreeze 配对）
            $byPicking = $load->items->groupBy('picking_id');
            foreach ($byPicking as $pickingId => $group) {
                $warehouseId = DB::table('delivery_picking')->where('id', $pickingId)->value('warehouse_id');
                $orderId = DB::table('delivery_picking')->where('id', $pickingId)->value('sales_order_id');
                if (! $warehouseId) {
                    continue;
                }
                // 按商品合并数量（同一商品可能有多行）
                $byProduct = $group->groupBy('product_id');
                foreach ($byProduct as $productId => $rows) {
                    $qty = (int) $rows->sum('quantity');
                    if ($qty <= 0) {
                        continue;
                    }
                    // 正式出库（传 relatedId=load_id, relatedType）
                    $this->stocks->stockOut(
                        (int) $productId,
                        (int) $warehouseId,
                        $qty,
                        $load->id,
                        'DeliveryLoad',
                        '装车出库 '.$load->load_no
                    );
                    // 释放配货时冻结的量（freeze 用 orderId 关联，unfreeze 也用同一 orderId）
                    $this->stocks->unfreeze(
                        (int) $productId,
                        (int) $warehouseId,
                        $qty,
                        (int) $orderId
                    );
                }
            }

            // 生成配送任务
            $tasks = $this->flow->createTasksFromLoad($load);

            $load->status = DeliveryLoad::STATUS_DELIVERING;
            $load->loaded_at = now();
            $load->save();

            DB::commit();

            return $this->success([
                'load' => $load->fresh(['items']),
                'tasks' => $tasks,
            ], '装车确认成功，已生成 '.count($tasks).' 个配送任务');
        } catch (StockRuleException $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        }
    }

    /** 删除装车单（仅待装车） */
    public function destroy(int $id)
    {
        $load = DeliveryLoad::find($id);
        if (! $load) {
            return $this->error('装车单不存在', 404);
        }
        if ($load->status !== DeliveryLoad::STATUS_PENDING) {
            return $this->error('仅待装车状态可删除', 422);
        }
        DB::beginTransaction();
        try {
            $load->items()->delete();
            $load->delete();
            DB::commit();

            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        }
    }

    /** 单号生成 */
    private function generateNo(string $prefix, string $table, string $column): string
    {
        $like = $prefix.date('Ymd').'%';
        $last = DB::table($table)->where($column, 'like', $like)->orderByDesc($column)->value($column);
        $seq = $last ? intval(substr($last, -6)) + 1 : 1;

        return $prefix.date('Ymd').str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
