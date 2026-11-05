<?php

namespace Modules\Member\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Member\Models\MemberConsumeLog;

/**
 * 会员消费记录
 *
 * 只读台账，记录会员每一笔储值/积分变动及变动后余额快照。
 * 不提供修改/删除接口——账务凭证不可篡改，出错时写反向记录冲正。
 */
class MemberConsumeLogController extends Controller
{
    use ResponseTrait;

    public function index(Request $request)
    {
        $query = MemberConsumeLog::query();

        if ($request->filled('keyword')) {
            $like = '%'.str_replace('%', '\%', $request->keyword).'%';
            $query->where(function ($q) use ($like) {
                $q->where('log_no', 'like', $like)
                    ->orWhere('member_no', 'like', $like)
                    ->orWhere('member_name', 'like', $like)
                    ->orWhere('related_order_no', 'like', $like);
            });
        }
        if ($request->filled('member_id')) {
            $query->where('member_id', $request->member_id);
        }
        if ($request->filled('consume_type')) {
            $query->where('consume_type', $request->consume_type);
        }
        if ($request->filled('related_order_no')) {
            $query->where('related_order_no', $request->related_order_no);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        return $this->paginated(
            $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page)
        );
    }

    public function show($id)
    {
        $log = MemberConsumeLog::with('member')->find($id);
        if (! $log) {
            return $this->notFound('消费记录不存在');
        }

        return $this->success($log);
    }

    /**
     * 积分日台账（QA2 D-01）：按「会员 × 自然日」一行 ——
     * 昨日积分 / 积分变动 / 兑换积分 / 今日积分。
     *
     * 与 Cash 域 cash/integral/ledger/members 的「会员 × 期间汇总」互补，
     * 对齐连凯的按日滚动台账口径。数据源同为 member_consume_logs（只追加台账），
     * 记账规则保持一致：变动 = Σearn_points（发放/退款回补），
     * 兑换 = Σuse_points（核销/兑换/抵扣），今日 = 昨日 + 变动 − 兑换。
     */
    public function dailyLedger(Request $request)
    {
        $scope = fn ($q) => $q->where('earn_points', '>', 0)->orWhere('use_points', '>', 0);

        $days = MemberConsumeLog::query()
            ->selectRaw('member_id, DATE(created_at) as stat_date')
            ->selectRaw('COALESCE(SUM(earn_points), 0) as earn_total')
            ->selectRaw('COALESCE(SUM(use_points), 0) as use_total')
            ->where($scope)
            ->when($request->filled('start_date'), fn ($q) => $q->whereDate('created_at', '>=', $request->start_date))
            ->when($request->filled('end_date'), fn ($q) => $q->whereDate('created_at', '<=', $request->end_date))
            ->when($request->filled('member_id'), fn ($q) => $q->where('member_id', (int) $request->member_id))
            ->groupByRaw('member_id, DATE(created_at)');

        // 昨日积分 = 当日之前所有积分变动的有符号累计（相关子查询，两驱动通用）。
        // 不能按 start_date 截断：窗口首日的期初要吃到窗口之前的历史累计。
        $rows = MemberConsumeLog::query()
            ->fromSub($days, 'd')
            ->join('members', 'members.id', '=', 'd.member_id')
            ->selectRaw('d.member_id, d.stat_date, d.earn_total, d.use_total')
            ->selectRaw('members.member_no, members.name as member_name')
            ->selectRaw('(SELECT COALESCE(SUM(l.earn_points - l.use_points), 0) FROM member_consume_logs l
                WHERE l.member_id = d.member_id AND (l.earn_points > 0 OR l.use_points > 0)
                  AND DATE(l.created_at) < d.stat_date) as points_yday')
            ->selectRaw('(SELECT COALESCE(SUM(l.earn_points - l.use_points), 0) FROM member_consume_logs l
                WHERE l.member_id = d.member_id AND (l.earn_points > 0 OR l.use_points > 0)
                  AND DATE(l.created_at) < d.stat_date) + d.earn_total - d.use_total as points_today')
            ->orderByDesc('d.stat_date')
            ->orderBy('d.member_id');

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));
        $paginator = $rows->paginate($pageSize, ['*'], 'page', $page);

        $list = collect($paginator->items())->map(fn ($r) => [
            'member_id' => (int) $r->member_id,
            'member_no' => $r->member_no,
            'member_name' => $r->member_name,
            'stat_date' => (string) $r->stat_date,
            'points_yday' => (int) $r->points_yday,
            'points_change' => (int) $r->earn_total,
            'points_exchange' => (int) $r->use_total,
            'points_today' => (int) $r->points_today,
        ])->all();

        return $this->success([
            'list' => $list,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    /** 汇总统计 */
    public function statistics(Request $request)
    {
        $query = MemberConsumeLog::query();
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $grouped = $query->selectRaw('consume_type, SUM(consume_amount) as amount, COUNT(*) as count')
            ->groupBy('consume_type')
            ->get();

        return $this->success([
            'total_count' => $query->clone()->count(),
            'total_recharge' => (float) MemberConsumeLog::where('consume_type', MemberConsumeLog::TYPE_RECHARGE)->sum('consume_amount'),
            'total_deduct_points' => (int) MemberConsumeLog::where('consume_type', MemberConsumeLog::TYPE_POINTS_DEDUCT)->sum('use_points'),
            'by_type' => $grouped,
        ]);
    }
}
