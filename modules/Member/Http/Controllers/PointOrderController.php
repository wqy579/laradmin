<?php

namespace Modules\Member\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Member\Models\Member;
use Modules\Member\Models\MemberConsumeLog;
use Modules\Member\Models\PointOrder;
use Modules\Member\Models\PointProduct;
use Modules\Member\Services\MemberService;

/**
 * 积分兑换订单
 *
 * 状态机：0待审核 → 1已审核待发货 → 2已发货 → 3已完成；0/1/2 可 → 4已驳回 / 5已取消
 *
 * 资金安全核心：
 *   - 只有「已审核」才真正扣减会员积分与兑换商品库存
 *   - 驳回/取消必须对称退回积分与库存——重复扣减是积分业务最常见的资金事故
 *   - 所有扣减在事务内对 member / point_product 加行锁串行化
 */
class PointOrderController extends Controller
{
    use ResponseTrait;

    public function __construct(private MemberService $memberService) {}

    public function index(Request $request)
    {
        $query = PointOrder::with(['member:id,member_no,name,phone']);

        if ($request->filled('keyword')) {
            $like = '%'.str_replace('%', '\%', $request->keyword).'%';
            $query->where(function ($q) use ($like) {
                $q->where('order_no', 'like', $like)
                    ->orWhere('member_no', 'like', $like)
                    ->orWhere('member_name', 'like', $like)
                    ->orWhere('product_name', 'like', $like)
                    ->orWhere('ship_no', 'like', $like);
            });
        }
        if ($request->filled('member_id')) {
            $query->where('member_id', $request->member_id);
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
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
        $order = PointOrder::with(['member', 'product'])->find($id);
        if (! $order) {
            return $this->notFound('兑换订单不存在');
        }

        $order->consume_logs = MemberConsumeLog::where('related_order_no', $order->order_no)->get();

        return $this->success($order);
    }

    /**
     * 创建兑换订单（后台代客创建）。
     *
     * 注意：创建时不扣积分、不扣库存——审核通过才扣。
     * 这里只做余额/库存/限购的预校验，给前端及时反馈。
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'member_id' => 'required|exists:members,id',
            'product_id' => 'required|exists:point_products,id',
            'quantity' => 'required|integer|min:1',
            'contact_phone' => 'nullable|string|max:20',
            'shipping_address' => 'nullable|string|max:255',
            'remark' => 'nullable|string|max:1000',
        ]);

        $member = Member::find($validated['member_id']);
        $product = PointProduct::find($validated['product_id']);

        if (! $member->isActive()) {
            return $this->error('会员已冻结，无法兑换', 422);
        }
        if ((int) $member->points < (int) $product->points * (int) $validated['quantity']) {
            return $this->error(
                '会员积分不足，当前积分 '.(int) $member->points.'，本次需要 '.((int) $product->points * (int) $validated['quantity']).' 积分',
                422
            );
        }
        if ((int) $product->stock < (int) $validated['quantity']) {
            return $this->error('兑换商品库存不足', 422);
        }

        // 每人兑换上限（0=不限）
        $limit = (int) $product->limit_per_person;
        if ($limit > 0) {
            $exchangedByMember = PointOrder::where('product_id', $product->id)
                ->where('member_id', $member->id)
                ->whereNotIn('status', [PointOrder::STATUS_REJECTED, PointOrder::STATUS_CANCELLED])
                ->sum('quantity');
            if ((int) $exchangedByMember + (int) $validated['quantity'] > $limit) {
                return $this->error("该会员已达兑换上限（每人限 {$limit} 件）", 422);
            }
        }

        $order = DB::transaction(function () use ($validated, $member, $product) {
            $pointsUnit = (int) $product->points;
            $quantity = (int) $validated['quantity'];
            $totalPoints = $pointsUnit * $quantity;

            return PointOrder::create([
                'order_no' => MemberService::generateNo('point_order', 'point_orders', 'order_no'),
                'member_id' => $member->id,
                'member_no' => $member->member_no,
                'member_name' => $member->name,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_image' => $product->image,
                'product_spec' => $product->spec,
                'points_unit' => $pointsUnit,
                'quantity' => $quantity,
                'total_points' => $totalPoints,
                'contact_phone' => $validated['contact_phone'] ?? null,
                'shipping_address' => $validated['shipping_address'] ?? null,
                'status' => PointOrder::STATUS_PENDING,
                'remark' => $validated['remark'] ?? null,
            ]);
        });

        return $this->created($order, '兑换订单已创建，待审核');
    }

    /** 审核通过：扣积分 + 扣库存 + 写台账 */
    public function approve($id)
    {
        $order = PointOrder::find($id);
        if (! $order) {
            return $this->notFound('兑换订单不存在');
        }
        if ($order->status !== PointOrder::STATUS_PENDING) {
            return $this->error('只有待审核订单可以审核', 422);
        }

        $admin = auth('admin')->user();
        try {
            $order = $this->memberService->approvePointOrder(
                $order,
                ['id' => $admin?->id, 'name' => $admin?->real_name ?? $admin?->username]
            );
        } catch (\Throwable $e) {
            return $this->error('审核失败：'.$e->getMessage(), 422);
        }

        return $this->success($order, '审核通过，已扣减积分与库存');
    }

    /** 驳回：已审核的退回积分与库存 */
    public function reject(Request $request, $id)
    {
        return $this->close($request, $id, PointOrder::STATUS_REJECTED, '已驳回');
    }

    /** 取消：已审核的退回积分与库存 */
    public function cancel(Request $request, $id)
    {
        return $this->close($request, $id, PointOrder::STATUS_CANCELLED, '已取消');
    }

    /** 发货：已审核待发货 → 已发货 */
    public function ship(Request $request, $id)
    {
        $order = PointOrder::find($id);
        if (! $order) {
            return $this->notFound('兑换订单不存在');
        }
        if ($order->status !== PointOrder::STATUS_APPROVED) {
            return $this->error('只有已审核待发货的订单可以发货', 422);
        }

        $admin = auth('admin')->user();
        $order->update([
            'status' => PointOrder::STATUS_SHIPPED,
            'ship_time' => now(),
            'ship_no' => $request->input('ship_no'),
            'ship_by' => $admin?->id,
        ]);

        return $this->success(PointOrder::find($id), '已发货');
    }

    /** 完成：已发货 → 已完成 */
    public function complete($id)
    {
        $order = PointOrder::find($id);
        if (! $order) {
            return $this->notFound('兑换订单不存在');
        }
        if ($order->status !== PointOrder::STATUS_SHIPPED) {
            return $this->error('只有已发货的订单可以完成', 422);
        }

        $order->update([
            'status' => PointOrder::STATUS_COMPLETED,
            'complete_time' => now(),
        ]);

        return $this->success(PointOrder::find($id), '订单已完成');
    }

    /** 统计 */
    public function statistics(Request $request)
    {
        return $this->success([
            'total_count' => PointOrder::count(),
            'pending_count' => PointOrder::where('status', PointOrder::STATUS_PENDING)->count(),
            'approved_count' => PointOrder::where('status', PointOrder::STATUS_APPROVED)->count(),
            'shipped_count' => PointOrder::where('status', PointOrder::STATUS_SHIPPED)->count(),
            'completed_count' => PointOrder::where('status', PointOrder::STATUS_COMPLETED)->count(),
            'total_points_used' => (int) PointOrder::whereNotIn('status', [
                PointOrder::STATUS_REJECTED, PointOrder::STATUS_CANCELLED,
            ])->sum('total_points'),
        ]);
    }

    private function close(Request $request, $id, int $targetStatus, string $message)
    {
        $order = PointOrder::find($id);
        if (! $order) {
            return $this->notFound('兑换订单不存在');
        }
        if (PointOrder::isFinal($order->status)) {
            return $this->error('终态订单不能'.$message, 422);
        }

        try {
            $order = $this->memberService->closePointOrder(
                $order,
                $targetStatus,
                $request->input('reason', '')
            );
        } catch (\Throwable $e) {
            return $this->error($message.'失败：'.$e->getMessage(), 422);
        }

        return $this->success($order, $message);
    }
}
