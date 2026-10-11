<?php

namespace Modules\Member\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Member\Models\Member;
use Modules\Member\Models\MemberConsumeLog;
use Modules\Member\Models\MemberRecharge;
use Modules\Member\Services\MemberService;

/**
 * 储值充值
 *
 * 状态机：0待审核 → 1已审核 / 2已驳回 / 3已取消
 *   - 只有「已审核」才增加会员储值余额并写现金流水
 *   - 驳回/取消不产生资金变动（此时尚未加钱）
 *   - 已审核单据禁止编辑/删除，冲正走红冲（后续扩展）
 */
class MemberRechargeController extends Controller
{
    use ResponseTrait;

    public function __construct(private MemberService $memberService) {}

    public function index(Request $request)
    {
        $query = MemberRecharge::with(['member:id,member_no,name,phone'])
            ->leftJoin('members', 'members.id', '=', 'member_recharges.member_id');

        if ($request->filled('keyword')) {
            $like = '%'.str_replace('%', '\%', $request->keyword).'%';
            $query->where(function ($q) use ($like) {
                $q->where('member_recharges.recharge_no', 'like', $like)
                    ->orWhere('member_recharges.member_name', 'like', $like)
                    ->orWhere('members.phone', 'like', $like);
            });
        }
        if ($request->filled('member_id')) {
            $query->where('member_recharges.member_id', $request->member_id);
        }
        if ($request->has('status')) {
            $query->where('member_recharges.status', $request->status);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('member_recharges.recharge_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('member_recharges.recharge_date', '<=', $request->end_date);
        }

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        return $this->paginated(
            $query->select('member_recharges.*')
                ->orderByDesc('member_recharges.id')
                ->paginate($pageSize, ['member_recharges.*'], 'page', $page)
        );
    }

    public function show($id)
    {
        $recharge = MemberRecharge::with('member')->find($id);
        if (! $recharge) {
            return $this->notFound('充值单不存在');
        }

        // 关联现金流水
        $recharge->cash_flow = DB::table('cash_flows')
            ->where('related_id', $id)
            ->where('related_type', 'MemberRecharge')
            ->first();

        // 关联消费台账
        $recharge->consume_logs = MemberConsumeLog::where('related_order_no', $recharge->recharge_no)
            ->get();

        return $this->success($recharge);
    }

    public function store(Request $request)
    {
        $validated = $this->validateRecharge($request);

        $member = Member::find($validated['member_id']);
        if (! $member) {
            return $this->error('会员不存在', 422);
        }
        if (! $member->isActive()) {
            return $this->error('会员已冻结，无法充值', 422);
        }

        $admin = auth('admin')->user();

        $recharge = DB::transaction(function () use ($validated, $member, $admin) {
            $giftAmount = round(max((float) ($validated['gift_amount'] ?? 0), 0), 2);
            $rechargeAmount = round(max((float) $validated['recharge_amount'], 0), 2);
            $totalAmount = round($rechargeAmount + $giftAmount, 2);

            return MemberRecharge::create([
                'recharge_no' => MemberService::generateNo('recharge', 'member_recharges', 'recharge_no'),
                'member_id' => $member->id,
                'member_no' => $member->member_no,
                'member_name' => $member->name,
                'recharge_amount' => $rechargeAmount,
                'gift_amount' => $giftAmount,
                'total_amount' => $totalAmount,
                'pay_method' => $validated['pay_method'] ?? 'cash',
                'recharge_date' => $validated['recharge_date'] ?? now()->toDateString(),
                'operator_id' => $admin?->id,
                'operator_name' => $admin?->real_name ?? $admin?->username,
                'status' => MemberRecharge::STATUS_PENDING,
                'remark' => $validated['remark'] ?? null,
            ]);
        });

        return $this->created($recharge, '充值单已创建，待审核');
    }

    public function update(Request $request, $id)
    {
        $recharge = MemberRecharge::find($id);
        if (! $recharge) {
            return $this->notFound('充值单不存在');
        }
        if ($recharge->status === MemberRecharge::STATUS_APPROVED) {
            return $this->error('已审核单据不能修改，请走红冲', 422);
        }

        $validated = $this->validateRecharge($request);

        $giftAmount = round(max((float) ($validated['gift_amount'] ?? 0), 0), 2);
        $rechargeAmount = round(max((float) $validated['recharge_amount'], 0), 2);
        $totalAmount = round($rechargeAmount + $giftAmount, 2);

        $recharge->update([
            'recharge_amount' => $rechargeAmount,
            'gift_amount' => $giftAmount,
            'total_amount' => $totalAmount,
            'pay_method' => $validated['pay_method'] ?? 'cash',
            'recharge_date' => $validated['recharge_date'] ?? now()->toDateString(),
            'remark' => $validated['remark'] ?? null,
        ]);

        return $this->success(MemberRecharge::find($id), '充值单已更新');
    }

    public function destroy($id)
    {
        $recharge = MemberRecharge::find($id);
        if (! $recharge) {
            return $this->notFound('充值单不存在');
        }
        if ($recharge->status === MemberRecharge::STATUS_APPROVED) {
            return $this->error('已审核单据不能删除', 422);
        }

        $recharge->delete();

        return $this->success(null, '充值单已删除');
    }

    /** 审核通过：增加储值 + 写现金流水 */
    public function approve($id)
    {
        $recharge = MemberRecharge::find($id);
        if (! $recharge) {
            return $this->notFound('充值单不存在');
        }
        if ($recharge->status === MemberRecharge::STATUS_APPROVED) {
            return $this->error('该单据已审核通过', 422);
        }
        if (in_array($recharge->status, [
            MemberRecharge::STATUS_REJECTED, MemberRecharge::STATUS_CANCELLED,
        ], true)) {
            return $this->error('已驳回/取消的单据不能审核，请删除后重新创建', 422);
        }

        $admin = auth('admin')->user();
        try {
            $recharge = $this->memberService->approveRecharge(
                $recharge,
                ['id' => $admin?->id, 'name' => $admin?->real_name ?? $admin?->username]
            );
        } catch (\Throwable $e) {
            return $this->error('审核失败：'.$e->getMessage(), 422);
        }

        return $this->success($recharge, '审核通过，储值已到账');
    }

    /** 驳回 */
    public function reject(Request $request, $id)
    {
        $recharge = MemberRecharge::find($id);
        if (! $recharge) {
            return $this->notFound('充值单不存在');
        }
        if ($recharge->status === MemberRecharge::STATUS_APPROVED) {
            return $this->error('已审核单据不能驳回，请走红冲', 422);
        }

        $admin = auth('admin')->user();
        $recharge = $this->memberService->closeRecharge(
            $recharge,
            MemberRecharge::STATUS_REJECTED,
            ['id' => $admin?->id, 'name' => $admin?->real_name ?? $admin?->username],
            $request->input('reason', '')
        );

        return $this->success($recharge, '已驳回');
    }

    /** 取消（制单人自助取消待审核单据） */
    public function cancel($id)
    {
        $recharge = MemberRecharge::find($id);
        if (! $recharge) {
            return $this->notFound('充值单不存在');
        }
        if ($recharge->status !== MemberRecharge::STATUS_PENDING) {
            return $this->error('只有待审核单据可以取消', 422);
        }

        $admin = auth('admin')->user();
        $recharge = $this->memberService->closeRecharge(
            $recharge,
            MemberRecharge::STATUS_CANCELLED,
            ['id' => $admin?->id, 'name' => $admin?->real_name ?? $admin?->username],
            '制单人取消'
        );

        return $this->success($recharge, '已取消');
    }

    /** 统计 */
    public function statistics(Request $request)
    {
        $query = MemberRecharge::where('status', MemberRecharge::STATUS_APPROVED);
        if ($request->filled('start_date')) {
            $query->whereDate('recharge_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('recharge_date', '<=', $request->end_date);
        }

        return $this->success([
            'total_amount' => (float) $query->sum('recharge_amount'),
            'gift_amount' => (float) $query->sum('gift_amount'),
            'count' => $query->count(),
            'pending_count' => MemberRecharge::where('status', MemberRecharge::STATUS_PENDING)->count(),
        ]);
    }

    private function validateRecharge(Request $request): array
    {
        return $request->validate([
            'member_id' => 'required|exists:members,id',
            'recharge_amount' => 'required|numeric|min:0.01',
            'gift_amount' => 'nullable|numeric|min:0',
            'pay_method' => ['nullable', 'string', 'max:20', 'in:cash,wechat,alipay,bank,other'],
            'recharge_date' => 'nullable|date',
            'remark' => 'nullable|string|max:1000',
        ]);
    }
}
