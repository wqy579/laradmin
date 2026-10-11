<?php

namespace Modules\Member\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Member\Models\Member;
use Modules\Member\Models\MemberConsumeLog;
use Modules\Member\Models\MemberLevel;
use Modules\Member\Services\MemberService;
use Modules\Order\Models\Customer;

/**
 * 会员档案
 *
 * 列表支持：关键词（会员编号/名称/联系人/手机/客户名）、等级、状态、注册时间区间。
 * 会员与客户 1:0..1，customer_id 唯一，重复创建时直接返回已有会员。
 */
class MemberController extends Controller
{
    use ResponseTrait;

    public function __construct(private MemberService $memberService) {}

    /** 列表 */
    public function index(Request $request)
    {
        $query = $this->applyFilters(Member::query(), $request)
            ->with(['level:id,name,color,discount_rate'])
            ->select([
                'members.*',
                'customers.name as customer_name_live',
            ]);

        $query->leftJoin('customers', 'customers.id', '=', 'members.customer_id');

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        return $this->paginated(
            $query->orderByDesc('members.id')->paginate($pageSize, ['members.*', 'customers.name'], 'page', $page)
        );
    }

    /** 详情（含等级、消费记录、最近充值/兑换） */
    public function show($id)
    {
        $member = Member::with(['level', 'customer'])->find($id);
        if (! $member) {
            return $this->notFound('会员不存在');
        }

        $member->recent_consume_logs = MemberConsumeLog::where('member_id', $id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return $this->success($member);
    }

    /** 创建（支持由客户一键转会员） */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'name' => 'required|string|max:100',
            'contact' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:20',
            'gender' => 'nullable|integer|in:0,1,2',
            'birthday' => 'nullable|date',
            'email' => 'nullable|email|max:100',
            'province' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:50',
            'district' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'level_id' => 'nullable|exists:member_levels,id',
            'register_date' => 'nullable|date',
            'status' => 'nullable|integer|in:0,1',
            'remark' => 'nullable|string|max:1000',
        ]);

        // customer_id 唯一：已存在会员则返回已有记录，保证 1:0..1
        if ($validated['customer_id'] ?? null) {
            $existing = Member::where('customer_id', $validated['customer_id'])->first();
            if ($existing) {
                return $this->success($existing, '该客户已存在会员档案');
            }
        }

        $customer = null;
        if ($validated['customer_id'] ?? null) {
            $customer = Customer::find($validated['customer_id']);
        }

        $operator = $this->currentAdmin();

        try {
            $member = $this->memberService->createMemberFromCustomer(array_merge($validated, [
                'customer_name' => $customer?->name,
                // 未提供信息时从客户档案回填，减少录入工作量
                'name' => $validated['name'] ?? $customer?->name ?? '',
                'contact' => $validated['contact'] ?? $customer?->contact ?? null,
                'phone' => $validated['phone'] ?? $customer?->phone ?? null,
                'address' => $validated['address'] ?? $customer?->address ?? null,
            ]), $operator ? ['id' => $operator->id, 'name' => $operator->name] : null);
        } catch (\Throwable $e) {
            return $this->error('创建失败：'.$e->getMessage(), 422);
        }

        if ($member->level_id != ($validated['level_id'] ?? 0)) {
            $member->level_id = (int) ($validated['level_id'] ?? 0) ?: $member->level_id;
            $member->save();
        }

        return $this->created(Member::with('level')->find($member->id), '会员创建成功');
    }

    /** 更新 */
    public function update(Request $request, $id)
    {
        $member = Member::find($id);
        if (! $member) {
            return $this->notFound('会员不存在');
        }

        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id|unique:members,customer_id,'.$id,
            'name' => 'nullable|string|max:100',
            'contact' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:20',
            'gender' => 'nullable|integer|in:0,1,2',
            'birthday' => 'nullable|date',
            'email' => 'nullable|email|max:100',
            'province' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:50',
            'district' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'level_id' => 'nullable|exists:member_levels,id',
            'points' => 'nullable|integer|min:0',
            'balance' => 'nullable|numeric|min:0',
            'status' => 'nullable|integer|in:0,1',
            'remark' => 'nullable|string|max:1000',
        ]);

        if (isset($validated['customer_id']) && $validated['customer_id']) {
            $customer = Customer::find($validated['customer_id']);
            $validated['customer_name'] = $customer?->name;
        } elseif ($validated['customer_id'] === null) {
            $validated['customer_name'] = null;
        } else {
            unset($validated['customer_id']);
        }

        $member->update($validated);

        return $this->success(Member::with('level')->find($id), '会员信息已更新');
    }

    /** 删除（有业务数据时禁止物理删除，改为冻结） */
    public function destroy($id)
    {
        $member = Member::find($id);
        if (! $member) {
            return $this->notFound('会员不存在');
        }

        $hasBusiness = MemberConsumeLog::where('member_id', $id)->exists()
            || (int) $member->total_orders > 0
            || (float) $member->balance > 0
            || (int) $member->points > 0;

        if ($hasBusiness) {
            return $this->error('该会员存在消费记录、储值或积分，不能删除，请改为冻结', 422);
        }

        $member->delete();

        return $this->success(null, '会员已删除');
    }

    /** 冻结（禁用后不能参与订单结算与积分兑换） */
    public function freeze($id)
    {
        return $this->setStatus($id, 0, '已冻结');
    }

    /** 解冻 */
    public function unfreeze($id)
    {
        return $this->setStatus($id, 1, '已解冻');
    }

    /** 批量冻结/解冻：body 传 status（0/1） */
    public function batchStatus(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'status' => 'required|integer|in:0,1',
        ]);

        $count = Member::whereIn('id', $validated['ids'])->update([
            'status' => $validated['status'],
            'updated_at' => now(),
        ]);

        return $this->success(['updated' => $count], $validated['status'] === 1 ? "已解冻 {$count} 人" : "已冻结 {$count} 人");
    }

    /** 导出 CSV（与列表同一套筛选，不带分页） */
    public function export(Request $request)
    {
        $list = $this->applyFilters(Member::query(), $request)
            ->with('level')
            ->leftJoin('customers', 'customers.id', '=', 'members.customer_id')
            ->select(['members.*', 'customers.name as customer_name_live'])
            ->orderByDesc('members.id')
            ->get();

        $csv = "\u{FEFF}"; // BOM：Excel 正确识别 UTF-8
        $csv .= "会员档案\n\n";
        $csv .= "会员编号,会员名称,客户名称,联系人,手机,等级,积分,储值余额,累计消费,累计订单,注册日期,状态,备注\n";

        foreach ($list as $m) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $this->csvCell($m->member_no),
                $this->csvCell($m->name),
                $this->csvCell($m->customer_name ?? ''),
                $this->csvCell($m->contact ?? ''),
                $this->csvCell($m->phone ?? ''),
                $this->csvCell($m->level?->name ?? ''),
                (int) $m->points,
                number_format((float) $m->balance, 2, '.', ''),
                number_format((float) $m->total_consume, 2, '.', ''),
                (int) $m->total_orders,
                $m->register_date?->format('Y-m-d') ?? '',
                $m->status === 1 ? '正常' : '冻结',
                $this->csvCell($m->remark ?? '')
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="members.csv"',
        ]);
    }

    /** 模糊搜索下拉（供订单申报、充值、兑换单据选择会员） */
    public function search(Request $request)
    {
        $keyword = $request->input('keyword', '');

        $query = Member::with('level:id,name,color')
            ->select('id', 'member_no', 'name', 'phone', 'customer_id', 'level_id', 'points', 'balance', 'status');

        if ($keyword !== '') {
            $like = '%'.str_replace('%', '\%', $keyword).'%';
            $query->where(function ($q) use ($like, $keyword) {
                $q->where('member_no', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('contact', 'like', $like);
            });
        }

        // 只在正常状态会员中搜索，冻结会员不应出现在下单选择里
        $query->where('status', Member::STATUS_ACTIVE);

        $limit = min(50, max(1, $request->integer('limit', 20)));

        return $this->success($query->orderByDesc('id')->limit($limit)->get());
    }

    /** 按客户查会员（订单选客户后自动带出会员信息） */
    public function byCustomer(Request $request)
    {
        $request->validate(['customer_id' => 'required|exists:customers,id']);

        $member = Member::with('level:id,name,color,discount_rate')
            ->where('customer_id', $request->customer_id)
            ->first();

        return $this->success($member);
    }

    /** 会员统计概览 */
    public function statistics()
    {
        $monthStart = now()->startOfMonth()->toDateString();

        $stats = [
            'total_members' => Member::count(),
            'active_members' => Member::where('status', Member::STATUS_ACTIVE)->count(),
            'frozen_members' => Member::where('status', Member::STATUS_FROZEN)->count(),
            'new_this_month' => Member::where('register_date', '>=', $monthStart)->count(),
            'new_this_month_amount' => (float) Member::where('register_date', '>=', $monthStart)
                ->sum('total_consume'),
            'total_balance' => (float) Member::sum('balance'),
            'total_points' => (int) Member::sum('points'),
            'total_consume_amount' => (float) Member::sum('total_consume'),
            'total_orders' => (int) Member::sum('total_orders'),
        ];

        // 本月消费趋势（近30天）
        $trendQuery = DB::table('member_consume_logs')
            ->where('consume_type', MemberConsumeLog::TYPE_ORDER)
            ->where('created_at', '>=', now()->subDays(30)->startOfDay())
            ->selectRaw('DATE(created_at) as date, COALESCE(SUM(consume_amount), 0) as amount')
            ->groupBy('DATE(created_at)');

        // SQLite 与 MySQL 的 DATE() 函数行为一致；用 selectRaw 避免字符串被当列名
        // 加反引号导致 MySQL 报 Unknown column 'DATE(created_at)'（生产 500 根因）。
        $trend = $trendQuery->orderBy(DB::raw('date'))->get();
        $stats['consume_trend'] = $trend;

        // 等级分布
        $levelDist = DB::table('members as m')
            ->leftJoin('member_levels as ml', 'ml.id', '=', 'm.level_id')
            ->select('m.level_id', 'ml.name as level_name', 'ml.color', 'ml.discount_rate',
                DB::raw('COUNT(m.id) as member_count'),
                DB::raw('COALESCE(SUM(m.balance), 0) as balance_total'),
                DB::raw('COALESCE(SUM(m.points), 0) as points_total'),
                DB::raw('COALESCE(SUM(m.total_consume), 0) as consume_total'))
            ->groupBy('m.level_id', 'ml.name', 'ml.color', 'ml.discount_rate')
            ->orderBy('ml.name')
            ->get();
        $stats['level_distribution'] = $levelDist;

        return $this->success($stats);
    }

    /** 某会员的消费记录（会员详情抽屉内用） */
    public function memberLogs($id)
    {
        $member = Member::find($id);
        if (! $member) {
            return $this->notFound('会员不存在');
        }

        $page = max(1, request('page', 1));
        $pageSize = min(100, max(10, request('page_size', 20)));

        $paginator = MemberConsumeLog::where('member_id', $id)
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);

        return $this->paginated($paginator);
    }

    // ------------------------------------------------------------------

    private function setStatus($id, int $status, string $message)
    {
        $member = Member::find($id);
        if (! $member) {
            return $this->notFound('会员不存在');
        }
        $member->status = $status;
        $member->save();

        return $this->success($member, $message);
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('keyword')) {
            $like = '%'.str_replace('%', '\%', $request->keyword).'%';
            $query->where(function ($q) use ($like) {
                $q->where('members.member_no', 'like', $like)
                    ->orWhere('members.name', 'like', $like)
                    ->orWhere('members.contact', 'like', $like)
                    ->orWhere('members.phone', 'like', $like)
                    ->orWhere('members.customer_name', 'like', $like);
            });
        }

        if ($request->filled('level_id')) {
            $query->where('members.level_id', $request->level_id);
        }
        if ($request->filled('customer_id')) {
            $query->where('members.customer_id', $request->customer_id);
        }
        if ($request->has('status')) {
            $query->where('members.status', $request->status);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('members.register_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('members.register_date', '<=', $request->end_date);
        }

        return $query;
    }

    private function currentAdmin()
    {
        return auth('admin')->user();
    }

    private function csvCell($value): string
    {
        $value = (string) ($value ?? '');
        if (preg_match('/[",\r\n]/', $value)) {
            $value = '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
