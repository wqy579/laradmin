<?php

namespace Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Delivery\Models\DeliveryTask;

/**
 * 配送任务管理（配送员配送）。由装车单自动生成，开始配送/确认送达/异常登记。
 */
class TaskController extends Controller
{
    use ResponseTrait;

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
        // 订单状态推进「配送中」
        if ($task->sales_order_id) {
            DB::table('sales_orders')->where('id', $task->sales_order_id)->update(['status' => '配送中']);
        }

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

        return $this->success($task, '已确认送达');
    }

    /** 登记异常 */
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
            'exception_type' => 'required|in:reject,damaged,address',
            'exception_remark' => 'required|string|max:500',
        ]);
        $task->update([
            'status' => DeliveryTask::STATUS_EXCEPTION,
            'exception_type' => $validated['exception_type'],
            'exception_remark' => $validated['exception_remark'],
        ]);

        return $this->success($task, '异常已登记');
    }
}
