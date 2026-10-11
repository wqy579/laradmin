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
