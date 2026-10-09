<?php

namespace Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Delivery\Models\DeliveryLoad;
use Modules\Delivery\Models\DeliveryTask;
use Modules\Order\Models\SalesOrder;
use Modules\Order\Models\SalesReturn;
use Modules\Order\Models\SalesReturnItem;
use Modules\Stock\Exceptions\StockRuleException;
use Modules\Stock\Services\StockService;

/**
 * 配送任务管理（配送员配送）。由装车单自动生成，开始配送/确认送达/异常登记/取消退货。
 *
 * 订单状态同步（文档 §8.2）：
 *   pending → 配送中（start）
 *   delivering → 已送达（deliver）
 *   paid → 已收款
 *   cancelled → 回退「待配送」（cancel，若该订单其他任务已终态）
 */
class TaskController extends Controller
{
    use ResponseTrait;

    public function __construct(private StockService $stocks) {}

    public function index(Request $request)
    {
        $query = DeliveryTask::query();

        if ($no = $request->input('task_no')) {
            $query->where('task_no', 'like', "%{$no}%");
        }
        if ($person = $request->input('delivery_person_name')) {
            $query->where('delivery_person_name', 'like', "%{$person}%");
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
                $query->where('created_at', '>=', $start.' 00:00:00');
            }
            if ($end ?? null) {
                $query->where('created_at', '<=', $end.' 23:59:59');
            }
        }

        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(200, max(10, (int) $request->input('page_size', 20)));

        return $this->paginated($query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page));
    }

    public function show(int $id)
    {
        $task = DeliveryTask::find($id);
        if (! $task) {
            return $this->error('配送任务不存在', 404);
        }
        // 附带商品明细（从装车单明细取）
        $items = DB::table('delivery_load_items as li')
            ->where('li.load_id', $task->load_id)
            ->where('li.check_id', $task->check_id)
            ->orderBy('li.sort')
            ->get([
                'li.product_id', 'li.product_code', 'li.product_name', 'li.spec', 'li.unit',
                'li.quantity', 'li.price', 'li.amount',
            ]);

        return $this->success([
            'task' => $task,
            'items' => $items,
        ]);
    }

    /** 开始配送 */
    public function start(Request $request, int $id)
    {
        $task = DeliveryTask::find($id);
        if (! $task) {
            return $this->error('配送任务不存在', 404);
        }
        if ($task->status !== DeliveryTask::STATUS_PENDING) {
            return $this->error('当前状态不可开始配送', 422);
        }
        $task->update([
            'status' => DeliveryTask::STATUS_DELIVERING,
            'started_at' => now(),
        ]);
        $this->syncOrderStatus($task->sales_order_id, '配送中');

        return $this->success($task, '开始配送');
    }

    /** 确认送达 */
    public function deliver(Request $request, int $id)
    {
        $task = DeliveryTask::find($id);
        if (! $task) {
            return $this->error('配送任务不存在', 404);
        }
        if ($task->status !== DeliveryTask::STATUS_DELIVERING) {
            return $this->error('当前状态不可确认送达', 422);
        }
        $task->update([
            'status' => DeliveryTask::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
        // 货已交付但未收款：订单进入既有词表中的「待收款」
        // （不可写「已送达」——该值不在 sales_orders.status 词表内，
        //   会让订单从 Order 侧统计与 tab 过滤中静默消失）
        $this->syncOrderStatus($task->sales_order_id, '待收款');

        return $this->success($task, '已确认送达');
    }

    /**
     * 登记异常。
     *
     * 异常类型五值（文档 §5.5.4）：拒收 / 破损 / 地址错误 / 联系不上客户 / 其他。
     * 已送达后仍可登记（客户拒收发生在送达环节）。
     */
    public function exception(Request $request, int $id)
    {
        $task = DeliveryTask::find($id);
        if (! $task) {
            return $this->error('配送任务不存在', 404);
        }
        if (! in_array($task->status, [DeliveryTask::STATUS_DELIVERING, DeliveryTask::STATUS_DELIVERED], true)) {
            return $this->error('当前状态不可登记异常', 422);
        }
        $validated = $request->validate([
            'exception_type' => 'required|in:'.implode(',', DeliveryTask::EXCEPTION_TYPES),
            'exception_remark' => 'required|string|max:500',
        ]);
        $task->update([
            'status' => DeliveryTask::STATUS_EXCEPTION,
            'exception_type' => $validated['exception_type'],
            'exception_remark' => $validated['exception_remark'],
        ]);
        // 不同步订单状态：异常是任务侧信息，「配送异常」不在 sales_orders.status 词表内。
        // 订单停留在原状态（配送中/待收款），后续由取消或退货流程推进。

        return $this->success($task, '异常已登记');
    }

    /**
     * 取消任务（文档 §5.5.6）：生成退货入库单 + 库存回补 + 装车单状态回退。
     *
     * 库存背景：装车确认时已对全部任务统一 stockOut 扣减库存
     * （LoadController::confirm），因此取消时无论任务是否已开始配送，
     * 都必须把对应商品数量退回仓库，否则账面库存永久少一份。
     *
     * 可取消状态：pending（未开始配送）/ delivering（已开始但未送达，客户拒收等）。
     * 已送达(delivered)与已收款(paid)不可取消——货已到客户手中，应走销售退货流程。
     *
     * 生成的退货入库单为 approved 状态（自动完成入库）：库存回补是数据正确性问题，
     * 不应卡在人工二次审批上；单据仍可在「销售退货」页面查看追溯。
     */
    public function cancel(Request $request, int $id)
    {
        $task = DeliveryTask::find($id);
        if (! $task) {
            return $this->error('配送任务不存在', 404);
        }
        if (! in_array($task->status, DeliveryTask::CANCELLABLE_STATUSES, true)) {
            return $this->error('仅「待配送」「配送中」或「异常」的任务可取消；已送达或已收款请走销售退货流程', 422);
        }

        $validated = $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        $items = $this->loadTaskItems($task);
        if ($items->isEmpty()) {
            return $this->error('任务无商品明细，无法生成退货入库单', 422);
        }

        DB::beginTransaction();
        try {
            $warehouseId = (int) $task->warehouse_id;
            if (! $warehouseId) {
                throw new \Exception('任务缺少仓库信息，无法生成退货入库单');
            }
            $admin = auth('admin')->user();

            // 1. 生成退货入库单（复用销售退货实体，return_type=cancel）
            $returnNo = $this->generateNo('RT', 'sales_returns', 'return_no');
            $totalQty = 0;
            $totalAmount = 0.0;

            $return = SalesReturn::create([
                'return_no' => $returnNo,
                'order_id' => $task->sales_order_id,
                'order_no' => $task->order_no,
                'customer_id' => $task->customer_id,
                'customer_name' => $task->customer_name,
                'warehouse_id' => $warehouseId,
                'return_date' => now()->toDateString(),
                'return_type' => 'cancel',
                'status' => 'auto_return',
                'total_skus' => $items->count(),
                'remark' => '配送任务取消自动生成：'.$task->task_no.'｜'.$validated['cancel_reason'],
                'created_by' => $admin?->id,
            ]);

            // 2. 明细 + 逐商品退回仓库（装车时已 stockOut，此处对称回补）
            foreach ($items as $li) {
                $qty = (int) $li->quantity;
                if ($qty <= 0 || ! $li->product_id) {
                    continue;
                }
                $price = (float) $li->price;
                SalesReturnItem::create([
                    'return_id' => $return->id,
                    'product_id' => (int) $li->product_id,
                    'product_code' => $li->product_code,
                    'product_name' => $li->product_name,
                    'spec' => $li->spec,
                    'unit' => $li->unit,
                    'order_qty' => $qty,
                    'returned_qty' => 0,
                    'return_qty' => $qty,
                    'return_price' => $price,
                    'return_amount' => round($qty * $price, 2),
                ]);
                // stockIn 只接受 6 个参数（无 remark），多传会被静默丢弃；
                // relatedType='SalesReturn' 已让台账可回溯到本单据。
                $this->stocks->stockIn(
                    (int) $li->product_id,
                    $warehouseId,
                    $qty,
                    null,
                    $return->id,
                    'SalesReturn'
                );
                $totalQty += $qty;
                $totalAmount += $qty * $price;
            }

            $return->update([
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
            ]);

            // 3. 更新任务状态
            $task->update([
                'status' => DeliveryTask::STATUS_CANCELLED,
                'cancel_reason' => $validated['cancel_reason'],
                'return_id' => $return->id,
            ]);

            // 4. 装车单回退：本装车单其他任务均终态时回退「已装车」，任务全取消时回退「待装车」
            $this->syncLoadStatus($task->load_id);

            // 5. 订单状态回退：该订单下所有任务均终态时回退「待配送」
            $this->rollbackOrderStatusIfAllTerminal($task);

            DB::commit();

            return $this->success([
                'task' => $task->fresh(),
                'return_order' => $return->fresh(['items']),
            ], '任务已取消，已生成退货入库单 '.$returnNo.'，商品已退回仓库');
        } catch (StockRuleException $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        }
    }

    /** 装车单明细（按 load_id + check_id 过滤出本任务的商品） */
    private function loadTaskItems(DeliveryTask $task)
    {
        return DB::table('delivery_load_items as li')
            ->where('li.load_id', $task->load_id)
            ->where('li.check_id', $task->check_id)
            ->orderBy('li.sort')
            ->get();
    }

    /**
     * 装车单状态回退：本装车单任务全部取消时回退「待装车」，可重新确认装车
     * （此时库存已回补，不会重复出库）。
     *
     * isEmpty 守卫不可省：Laravel 的 every() 对空集合返回 true，
     * 否则空装车单会被错误回退。
     */
    private function syncLoadStatus(int $loadId): void
    {
        $tasks = DeliveryTask::where('load_id', $loadId)->get();
        if ($tasks->isEmpty() || ! $this->allTerminal($tasks, fn ($t) => $t->status === DeliveryTask::STATUS_CANCELLED)) {
            return;
        }
        DeliveryLoad::where('id', $loadId)->update(['status' => 'pending', 'loaded_at' => null]);
    }

    /** 订单状态回退：该订单所有任务均终态时回退「待配送」 */
    private function rollbackOrderStatusIfAllTerminal(DeliveryTask $task): void
    {
        if (! $task->sales_order_id) {
            return;
        }
        $tasks = DeliveryTask::where('sales_order_id', $task->sales_order_id)->get();
        if ($tasks->isEmpty() || ! $this->allTerminal($tasks)) {
            return;
        }
        $this->syncOrderStatus($task->sales_order_id, '待配送');
    }

    /**
     * 集合内是否全部满足条件。默认条件 = 处于 DeliveryTask::TERMINAL_STATUSES。
     *
     * 终态含 exception：exception 既不可推进也不可取消，若不视为终态，
     * 「全部终态」的判定会永远为 false，装车单与订单会被永久卡在配送中。
     */
    private function allTerminal(
        Collection $tasks,
        ?\Closure $condition = null,
    ): bool {
        $condition ??= fn ($t) => in_array($t->status, DeliveryTask::TERMINAL_STATUSES, true);

        return $tasks->every($condition);
    }

    /** 同步销售订单状态（0 行影响即静默跳过，无需先 exists() 探一次） */
    private function syncOrderStatus(?int $orderId, string $status): void
    {
        if ($orderId) {
            SalesOrder::where('id', $orderId)->update(['status' => $status]);
        }
    }

    /** 单号生成：前缀+Ymd+6位流水 */
    private function generateNo(string $prefix, string $table, string $column): string
    {
        $like = $prefix.date('Ymd').'%';
        $last = DB::table($table)->where($column, 'like', $like)->orderByDesc($column)->value($column);
        $seq = $last ? intval(substr($last, -6)) + 1 : 1;

        return $prefix.date('Ymd').str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
