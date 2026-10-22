<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\VisitRemindLog;

/**
 * T5.4 业务员拜访提醒（business/visit-remind）。
 *
 * 四卡 KPI：
 *   - pending   待提醒（status=0 且 remind_date>=today，非逾期）
 *   - overdue   逾期（status=0 且 remind_date<today，前端标红的数据源）
 *   - today_due 今日到期（status=0 且 remind_date=today）
 *   - reminded  已提醒（status=1）
 *
 * sms_enabled：读 config('sms.enabled')。短信通道未上线（默认 false），
 * 前端据此把「提醒/批量提醒」按钮置灰（UI 保留）；后端不因 sms_enabled=false
 * 拒绝请求——通道上线后配置翻 true 即可，接口契约不变。
 *
 * 路由（由主会话统一接入 modules/Order/routes/admin.php，静态路由必须在前）：
 *   GET    business/visit-remind            → index
 *   POST   business/visit-remind            → store
 *   POST   business/visit-remind/remind     → remind
 *   POST   business/visit-remind/batch-remind → batchRemind
 */
class VisitRemindController extends Controller
{
    /**
     * KPI 四卡 + 待提醒列表（分页）。
     *
     * 列表筛选：employee_id / customer_id / status / remind_date 范围；
     * 每行经模型 $appends 自带 is_overdue 布尔。
     */
    public function index(Request $request)
    {
        $today = today();

        // 四卡：同一条口径各查一次，pending 与 overdue 按 remind_date 与 today 的关系互斥
        $kpi = [
            'pending' => VisitRemindLog::pending()->where('remind_date', '>=', $today)->count(),
            'overdue' => VisitRemindLog::overdue()->count(),
            'today_due' => VisitRemindLog::pending()->whereDate('remind_date', $today)->count(),
            'reminded' => VisitRemindLog::where('status', 1)->count(),
        ];

        $query = VisitRemindLog::with(['employee', 'customer', 'visitLog']);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->filled('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_start')) {
            $query->whereDate('remind_date', '>=', $request->date_start);
        }
        if ($request->filled('date_end')) {
            $query->whereDate('remind_date', '<=', $request->date_end);
        }

        $query->orderBy('remind_date', 'asc')->orderBy('id', 'desc');
        $pager = $query->paginate($request->integer('page_size', 20));

        return $this->success([
            'pending' => $kpi['pending'],
            'overdue' => $kpi['overdue'],
            'today_due' => $kpi['today_due'],
            'reminded' => $kpi['reminded'],
            'sms_enabled' => (bool) config('sms.enabled', false),
            'list' => $pager->items(),
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'last_page' => $pager->lastPage(),
        ]);
    }

    /**
     * 手工新建一条提醒（visit_log_id 可空 = 按计划新建提醒）。
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:auth_user,id',
            'customer_id' => 'required|exists:customers,id',
            'remind_date' => 'required|date',
            'visit_log_id' => 'nullable|integer|exists:visit_logs,id',
            'remind_type' => 'nullable|string|max:20',
            'remark' => 'nullable|string|max:500',
        ]);

        $remind = VisitRemindLog::create(array_merge($validated, [
            'status' => 0,
            'created_by' => auth('admin')->id(),
        ]));

        return $this->created($remind, '创建成功');
    }

    /**
     * 单条提醒发送/标记已提醒（POST business/visit-remind/remind，body: {id}）。
     *
     * status 0→1、sent_at=now、sent_by=当前登录人；已提醒返回 422。
     *
     * 【短信通道接入点】后续接短信在此方法内、DB 更新之前插入：
     *   if (config('sms.enabled', false)) { SmsClient::send($remind->employee, $content); }
     * 通道未上线不拦请求：前端已按 sms_enabled 置灰按钮，接口保持可用，
     * 避免通道上线后再改后端契约。
     */
    public function remind(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|integer|exists:visit_remind_log,id',
        ]);

        $remind = VisitRemindLog::findOrFail($validated['id']);

        if ((int) $remind->status === 1) {
            return $this->error('该提醒已提醒过', 422);
        }

        // TODO(sms): 短信通道上线后，在此发送短信再落库
        $remind->update([
            'status' => 1,
            'sent_at' => now(),
            'sent_by' => auth('admin')->id(),
        ]);

        return $this->success($remind, '已提醒');
    }

    /**
     * 批量提醒（POST business/visit-remind/batch-remind，body: {ids: []}）。
     *
     * 事务内循环标记：已提醒的计 skipped 不报错，其余 success；
     * 返回 {success, skipped}。
     */
    public function batchRemind(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:visit_remind_log,id',
        ]);

        $adminId = auth('admin')->id();
        $result = DB::transaction(function () use ($validated, $adminId) {
            $success = 0;
            $skipped = 0;

            $rows = VisitRemindLog::whereIn('id', $validated['ids'])->lockForUpdate()->get();
            foreach ($rows as $remind) {
                if ((int) $remind->status === 1) {
                    $skipped++;
                    continue;
                }
                // TODO(sms): 短信通道上线后，在此发送短信
                $remind->update([
                    'status' => 1,
                    'sent_at' => now(),
                    'sent_by' => $adminId,
                ]);
                $success++;
            }

            return ['success' => $success, 'skipped' => $skipped];
        });

        return $this->success($result, '批量提醒完成');
    }
}
