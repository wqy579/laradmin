<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * T5.4 业务员拜访提醒（订货中心）
 *
 * 状态机：pending（待提醒）→ sent（已提醒）；pending 可作废为 cancelled。
 * 已提醒不可重复发送、不可作废，均报 422。全程留痕（order_operation_logs）。
 *
 * 路由：
 *   - GET    business/visit-reminder          列表（keyword/status 过滤）
 *   - POST   business/visit-reminder          新建提醒（默认 pending）
 *   - GET    business/visit-reminder/{id}     详情（含全程留痕 history）
 *   - POST   business/visit-reminder/{id}/send 发送提醒（→ sent）
 *   - DELETE business/visit-reminder/{id}     作废（pending → cancelled）
 */
class VisitReminderController extends Controller
{
    use ResponseTrait;

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('visit_reminders as vr')
            ->join('auth_user as e', 'e.id', '=', 'vr.employee_id')
            ->leftJoin('customers as c', 'c.id', '=', 'vr.customer_id')
            ->select(
                'vr.id',
                'vr.remind_no',
                'vr.employee_id',
                'e.real_name as employee',
                'vr.customer_id',
                'c.name as customer',
                'vr.visit_date',
                'vr.content',
                'vr.status',
                'vr.sent_at',
                'vr.created_at',
            );

        if ($keyword = trim((string) $request->query('keyword', ''))) {
            $query->where(function ($q) use ($keyword) {
                $q->where('vr.remind_no', 'like', "%{$keyword}%")
                    ->orWhere('e.real_name', 'like', "%{$keyword}%")
                    ->orWhere('c.name', 'like', "%{$keyword}%")
                    ->orWhere('vr.content', 'like', "%{$keyword}%");
            });
        }
        if ($status = $request->query('status', '')) {
            $query->where('vr.status', $status);
        }
        if ($employeeId = $request->integer('employee_id', 0)) {
            $query->where('vr.employee_id', $employeeId);
        }

        return $this->paginated(
            $query->orderByDesc('vr.id')
                ->paginate(min(200, max(10, $request->integer('page_size', 20))))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|integer|exists:auth_user,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'visit_date' => 'required|date',
            'content' => 'nullable|string|max:500',
        ]);

        $id = DB::transaction(function () use ($validated) {
            $remindNo = $this->nextRemindNo();
            $admin = auth('admin')->user();

            $reminderId = DB::table('visit_reminders')->insertGetId([
                'remind_no' => $remindNo,
                'employee_id' => $validated['employee_id'],
                'customer_id' => $validated['customer_id'] ?? null,
                'visit_date' => $validated['visit_date'],
                'content' => $validated['content'] ?? null,
                'status' => 'pending',
                'created_by' => $admin?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->log($reminderId, $remindNo, '创建', null, 'pending', $admin, '新建拜访提醒');

            return $reminderId;
        });

        return $this->success([
            'id' => $id,
            'remind_no' => DB::table('visit_reminders')->where('id', $id)->value('remind_no'),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $reminder = $this->query()->where('vr.id', $id)->first();
        if (! $reminder) {
            return $this->notFound('拜访提醒不存在');
        }

        $history = DB::table('order_operation_logs')
            ->where('order_type', 'visit_reminder')
            ->where('order_id', $id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn ($log) => [
                'id' => (int) $log->id,
                'action' => $log->action,
                'action_label' => $log->action_label,
                'operator' => $log->operator_name ?: $log->user_name,
                'detail' => $log->detail,
                'remark' => $log->remark,
                'created_at' => $log->created_at,
            ]);

        return $this->success(array_merge((array) $reminder, [
            'history' => $history,
        ]));
    }

    /**
     * F3 拜访提醒 · 统计（4 KPI 卡）
     *
     * 返回：待提醒 / 已提醒 / 本月应访 / 本月覆盖率（已访 ÷ 应访 × 100）。
     * 应访 = 本月业务员名下线路（routes）挂载的启用客户数；已访 = 本月有签到记录的去重客户数。
     */
    public function stats(): JsonResponse
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $pending = DB::table('visit_reminders')->where('status', 'pending')->count();
        $sent = DB::table('visit_reminders')->where('status', 'sent')->count();
        $cancelled = DB::table('visit_reminders')->where('status', 'cancelled')->count();
        $todayPending = DB::table('visit_reminders')
            ->where('status', 'pending')
            ->whereDate('visit_date', now()->toDateString())
            ->count();

        // 应访：本月生效线路（routes → customers is_active=1 且挂了线路）的客户总量
        $planCustomers = (int) DB::table('customers')
            ->where('is_active', 1)
            ->whereNotNull('route_id')
            ->count();
        // 已访：本月有签到记录的去重客户数（口径与 VisitLogController::achievement 一致）
        $reachedCustomers = DB::table('visit_logs')
            ->whereBetween('checkin_time', [$monthStart.' 00:00:00', $monthEnd.' 23:59:59'])
            ->distinct()
            ->count('customer_id');

        $coverageRate = $planCustomers > 0 ? round($reachedCustomers / $planCustomers * 100, 1) : 0;

        return $this->success([
            'pending' => $pending,
            'sent' => $sent,
            'cancelled' => $cancelled,
            'today_pending' => $todayPending,
            'plan_customers' => $planCustomers,
            'reached_customers' => $reachedCustomers,
            'coverage_rate' => $coverageRate,
        ]);
    }

    /**
     * F3 拜访提醒 · 批量提醒
     *
     * 将多个待提醒记录一次性置为「已提醒」（站内信 + 短信占位），逐条留痕。
     * 仅 pending 可发送；其余跳过并计数。返回 { sent, skipped, ids }。
     */
    public function batchSend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));

        $admin = auth('admin')->user();
        $sent = 0;
        $skipped = 0;
        $sentIds = [];

        DB::transaction(function () use ($ids, $admin, &$sent, &$skipped, &$sentIds) {
            foreach ($ids as $id) {
                $reminder = DB::table('visit_reminders')->find($id);
                if (! $reminder || $reminder->status !== 'pending') {
                    $skipped++;

                    continue;
                }

                DB::table('visit_reminders')->where('id', $id)->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'sent_by' => $admin?->id,
                    'updated_at' => now(),
                ]);

                $this->log(
                    $id,
                    $reminder->remind_no,
                    '已提醒',
                    $reminder->status,
                    'sent',
                    $admin,
                    '批量提醒已发送给业务员（站内信 + 短信占位）：'.$reminder->content
                );

                $sent++;
                $sentIds[] = $id;
            }
        });

        return $this->success([
            'sent' => $sent,
            'skipped' => $skipped,
            'ids' => $sentIds,
        ], '批量提醒完成');
    }

    public function send(int $id, Request $request): JsonResponse
    {
        $reminder = DB::table('visit_reminders')->find($id);
        if (! $reminder) {
            return $this->notFound('拜访提醒不存在');
        }
        if ($reminder->status !== 'pending') {
            return $this->error('仅待提醒的提醒可发送', 422);
        }

        $admin = auth('admin')->user();

        DB::transaction(function () use ($id, $reminder, $request, $admin) {
            DB::table('visit_reminders')->where('id', $id)->update([
                'status' => 'sent',
                'sent_at' => now(),
                'sent_by' => $admin?->id,
                'updated_at' => now(),
            ]);

            $this->log(
                $id,
                $reminder->remind_no,
                '已提醒',
                $reminder->status,
                'sent',
                $admin,
                '拜访提醒已发送给业务员：'.$request->input('remark', $reminder->content)
            );
        });

        return $this->success(['id' => $id, 'status' => 'sent']);
    }

    public function destroy(int $id): JsonResponse
    {
        $reminder = DB::table('visit_reminders')->find($id);
        if (! $reminder) {
            return $this->notFound('拜访提醒不存在');
        }
        if ($reminder->status !== 'pending') {
            return $this->error('已提醒的提醒不可作废', 422);
        }

        $admin = auth('admin')->user();

        DB::transaction(function () use ($id, $reminder, $admin) {
            DB::table('visit_reminders')->where('id', $id)->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);

            $this->log($id, $reminder->remind_no, '取消', $reminder->status, 'cancelled', $admin, '拜访提醒已作废');
        });

        return $this->success(['id' => $id, 'status' => 'cancelled']);
    }

    private function query()
    {
        return DB::table('visit_reminders as vr')
            ->join('auth_user as e', 'e.id', '=', 'vr.employee_id')
            ->leftJoin('customers as c', 'c.id', '=', 'vr.customer_id')
            ->select(
                'vr.*',
                'e.real_name as employee',
                'c.name as customer',
            );
    }

    private function nextRemindNo(): string
    {
        $prefix = 'VRS'.now()->format('Ymd');
        $seq = (int) DB::table('visit_reminders')->where('remind_no', 'like', $prefix.'%')->count() + 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function log(
        int $reminderId,
        string $remindNo,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        $admin,
        string $detail
    ): void {
        DB::table('order_operation_logs')->insert([
            'order_id' => $reminderId,
            'order_no' => $remindNo,
            'order_type' => 'visit_reminder',
            'user_id' => $admin?->id,
            'user_name' => $admin?->username,
            'operator_id' => $admin?->id,
            'operator_name' => $admin?->username,
            'action' => $action,
            'action_label' => $action,
            'detail' => mb_substr($detail, 0, 500),
            'remark' => mb_substr($detail, 0, 500),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
