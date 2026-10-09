<?php

namespace Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Delivery\Models\DeliveryCollection;
use Modules\Delivery\Models\DeliveryTask;

/**
 * 配送收款管理（配送员收款）。对已送达配送任务收款，支持部分收款。
 * 挂账→customers.balance 增加；其他方式→cash_flows 流水记账。
 */
class CollectionController extends Controller
{
    use ResponseTrait;

    public function index(Request $request)
    {
        $query = DeliveryCollection::query();

        if ($no = $request->input('collection_no')) {
            $query->where('collection_no', 'like', "%{$no}%");
        }
        if ($taskNo = $request->input('task_no')) {
            $query->where('task_no', 'like', "%{$taskNo}%");
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
                $query->where('collect_date', '>=', $start);
            }
            if ($end ?? null) {
                $query->where('collect_date', '<=', $end);
            }
        }

        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(200, max(10, (int) $request->input('page_size', 20)));

        return $this->paginated($query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page));
    }

    public function show(int $id)
    {
        $collection = DeliveryCollection::find($id);
        if (! $collection) {
            return $this->error('收款单不存在', 404);
        }

        return $this->success($collection);
    }

    /** 新增收款：对已送达配送任务收款 */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'task_id' => 'required|integer|exists:delivery_task,id',
            'received_amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:现金,微信,支付宝,银行卡,挂账',
            'collect_date' => 'nullable|date',
            'remark' => 'nullable|string',
        ]);

        $task = DeliveryTask::find($validated['task_id']);
        if (! in_array($task->status, [DeliveryTask::STATUS_DELIVERED, DeliveryTask::STATUS_PAID], true)) {
            return $this->error('只能对已送达的任务收款', 422);
        }

        $receivable = (float) $task->order_amount;
        $alreadyPaid = (float) $task->paid_amount;
        $unpaid = round($receivable - $alreadyPaid, 2);
        $received = round((float) $validated['received_amount'], 2);
        if ($received > $unpaid + 0.01) {
            return $this->error('收款金额不能超过待收金额', 422);
        }

        $admin = auth('admin')->user();
        $collectionNo = $this->generateNo('CR', 'delivery_collection', 'collection_no');
        $collectDate = $validated['collect_date'] ?? now()->toDateString();

        DB::beginTransaction();
        try {
            $collection = DeliveryCollection::create([
                'collection_no' => $collectionNo,
                'task_id' => $task->id,
                'task_no' => $task->task_no,
                'sales_order_id' => $task->sales_order_id,
                'order_no' => $task->order_no,
                'customer_id' => $task->customer_id,
                'customer_name' => $task->customer_name,
                'delivery_person_id' => $task->delivery_person_id,
                'delivery_person_name' => $task->delivery_person_name,
                'employee_id' => $task->employee_id,
                'receivable_amount' => $receivable,
                'received_amount' => $received,
                'payment_method' => $validated['payment_method'],
                'collect_date' => $collectDate,
                'status' => 'pending',
                'remark' => $validated['remark'] ?? null,
            ]);

            // 更新任务已收金额与状态
            $newPaid = round($alreadyPaid + $received, 2);
            $task->paid_amount = $newPaid;
            $task->unpaid_amount = round($receivable - $newPaid, 2);
            if ($newPaid >= $receivable - 0.01) {
                $task->status = DeliveryTask::STATUS_PAID;
                $task->collected_at = now();
                $collection->status = DeliveryCollection::STATUS_PAID;
                // 订单状态推进「已收款」
                if ($task->sales_order_id) {
                    DB::table('sales_orders')->where('id', $task->sales_order_id)->update([
                        'status' => '已收款',
                        'paid_amount' => DB::raw("paid_amount + {$received}"),
                    ]);
                }
            } else {
                $task->status = DeliveryTask::STATUS_DELIVERED; // 保持已送达
                $collection->status = DeliveryCollection::STATUS_PARTIAL;
                // 订单状态推进「待收款」(部分)
                if ($task->sales_order_id) {
                    DB::table('sales_orders')->where('id', $task->sales_order_id)->update([
                        'status' => '待收款',
                        'paid_amount' => DB::raw("paid_amount + {$received}"),
                    ]);
                }
            }
            $task->save();
            $collection->save();

            // 挂账→客户应收余额增加；其他方式→写现金流水
            if ($validated['payment_method'] === '挂账') {
                if ($task->customer_id) {
                    DB::table('customers')->where('id', $task->customer_id)
                        ->increment('balance', $received);
                }
            } else {
                $this->writeCashFlow($collection, $task);
            }

            DB::commit();

            return $this->created($collection, '收款成功');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        }
    }

    /** 待收款配送任务列表（已送达可收款） */
    public function pendingTasks(Request $request)
    {
        $list = DeliveryTask::where('status', DeliveryTask::STATUS_DELIVERED)
            ->orderByDesc('id')
            ->limit(100)
            ->get([
                'id', 'task_no', 'customer_name', 'order_amount',
                'paid_amount', 'unpaid_amount',
            ]);

        return $this->success($list);
    }

    /** 写现金流水（参考 ReceiveController::store） */
    private function writeCashFlow(DeliveryCollection $collection, DeliveryTask $task): void
    {
        $admin = auth('admin')->user();
        DB::table('cash_flows')->insert([
            'flow_no' => 'CF'.date('YmdHis').strtoupper(Str::random(4)),
            'flow_type' => 'receive',
            'customer_id' => $task->customer_id,
            'supplier_id' => null,
            'related_id' => $collection->id,
            'related_type' => 'DeliveryCollection',
            'flow_date' => $collection->collect_date,
            'amount' => (float) $collection->received_amount,
            'payment_method' => $collection->payment_method,
            'remark' => '配送收款 '.$collection->collection_no,
            'created_by' => $admin?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function generateNo(string $prefix, string $table, string $column): string
    {
        $like = $prefix.date('Ymd').'%';
        $last = DB::table($table)->where($column, 'like', $like)->orderByDesc($column)->value($column);
        $seq = $last ? intval(substr($last, -6)) + 1 : 1;

        return $prefix.date('Ymd').str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
