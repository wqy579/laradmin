<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\SalesOrder;

/**
 * T5.2 订货调整（订货中心）
 *
 * 针对已存在的销售订单发起调整：
 *   - POST   business/order-adjustment            新增（modify 调数量 / void 作废整单）
 *   - GET    business/order-adjustment            列表
 *   - GET    business/order-adjustment/{id}       详情（含全程留痕 history）
 *   - PUT    business/order-adjustment/{id}       修改（仅 pending，可改原因/明细）
 *   - DELETE business/order-adjustment/{id}       作废（仅 pending）
 *   - POST   business/order-adjustment/{id}/approve   审核通过（落地到源订单）
 *   - POST   business/order-adjustment/{id}/reject    审核驳回（不动源订单）
 *
 * 全程留痕：每个动作写 order_operation_logs（order_type=order_adjustment）；
 * approve 落地时给源订单（order_type=sales_order）补一条「订货调整」留痕。
 */
class OrderAdjustController extends Controller
{
    use ResponseTrait;

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('order_adjustments as oa')
            ->join('sales_orders as so', 'so.id', '=', 'oa.order_id')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('auth_user as u', 'u.id', '=', 'oa.created_by')
            ->select(
                'oa.id',
                'oa.adjust_no',
                'oa.order_id',
                'so.order_no',
                'oa.type',
                'oa.reason',
                'oa.status',
                'c.name as customer',
                'u.username as creator',
                'oa.created_at',
                'oa.applied_at',
            );

        if ($keyword = trim((string) $request->query('keyword', ''))) {
            $query->where(function ($q) use ($keyword) {
                $q->where('oa.adjust_no', 'like', "%{$keyword}%")
                    ->orWhere('so.order_no', 'like', "%{$keyword}%");
            });
        }
        if ($status = $request->query('status', '')) {
            $query->where('oa.status', $status);
        }
        if ($type = $request->query('type', '')) {
            $query->where('oa.type', $type);
        }

        $list = $query->orderByDesc('oa.id')
            ->paginate(min(200, max(10, $request->integer('page_size', 20))))
            ->through(fn ($row) => (array) $row);

        return $this->paginated($list);
    }

    public function show(int $id): JsonResponse
    {
        $adjustment = DB::table('order_adjustments as oa')
            ->join('sales_orders as so', 'so.id', '=', 'oa.order_id')
            ->leftJoin('auth_user as u', 'u.id', '=', 'oa.created_by')
            ->where('oa.id', $id)
            ->select('oa.*', 'so.order_no', 'u.username as creator')
            ->first();
        if (! $adjustment) {
            return $this->notFound('订货调整单不存在');
        }

        $items = DB::table('order_adjustment_items as oai')
            ->leftJoin('products as p', 'p.id', '=', 'oai.product_id')
            ->where('oai.adjustment_id', $id)
            ->get(['oai.product_id', 'p.name as product', 'oai.old_qty', 'oai.new_qty']);

        return $this->success(array_merge((array) $adjustment, [
            'items' => $items,
            'history' => $this->history($id),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|integer|exists:sales_orders,id',
            'type' => 'required|in:modify,void',
            'reason' => 'nullable|string|max:500',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.old_qty' => 'required|numeric|min:0',
            'items.*.new_qty' => 'required|numeric|min:0',
        ]);

        $order = DB::table('sales_orders')->find($validated['order_id']);
        if (! in_array($order->status, ['draft', 'pending'], true)) {
            return $this->error('仅待处理的订单可发起调整', 422);
        }
        if ($validated['type'] === 'modify') {
            if (empty($validated['items'])) {
                return $this->error('调整数量必须提供明细 items', 422);
            }
            if (! $this->assertItemsMatchOrder((int) $order->id, $validated['items'])) {
                return $this->error('调整明细与订单明细不一致（商品或调整前数量不符）', 422);
            }
        }

        $id = DB::transaction(function () use ($validated) {
            $adjustNo = $this->nextAdjustNo();
            $admin = auth('admin')->user();

            $adjustmentId = DB::table('order_adjustments')->insertGetId([
                'adjust_no' => $adjustNo,
                'order_id' => $validated['order_id'],
                'type' => $validated['type'],
                'reason' => $validated['reason'] ?? null,
                'status' => 'pending',
                'created_by' => $admin?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($validated['items'] ?? [] as $item) {
                DB::table('order_adjustment_items')->insert([
                    'adjustment_id' => $adjustmentId,
                    'product_id' => $item['product_id'],
                    'old_qty' => $item['old_qty'],
                    'new_qty' => $item['new_qty'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->log($adjustmentId, $adjustNo, '创建', $admin, '发起'.($validated['type'] === 'modify' ? '数量调整' : '整单作废').'申请');

            return $adjustmentId;
        });

        return $this->success([
            'id' => $id,
            'adjust_no' => DB::table('order_adjustments')->where('id', $id)->value('adjust_no'),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $adjustment = DB::table('order_adjustments')->find($id);
        if (! $adjustment) {
            return $this->notFound('订货调整单不存在');
        }
        if ($adjustment->status !== 'pending') {
            return $this->error('仅待审核的调整单可修改', 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.old_qty' => 'required|numeric|min:0',
            'items.*.new_qty' => 'required|numeric|min:0',
        ]);

        if (! empty($validated['items'])) {
            if ($adjustment->type !== 'modify') {
                return $this->error('作废调整单不支持明细', 422);
            }
            if (! $this->assertItemsMatchOrder((int) $adjustment->order_id, $validated['items'])) {
                return $this->error('调整明细与订单明细不一致（商品或调整前数量不符）', 422);
            }
        }

        DB::transaction(function () use ($id, $validated, $adjustment) {
            if (array_key_exists('reason', $validated)) {
                DB::table('order_adjustments')->where('id', $id)->update([
                    'reason' => $validated['reason'],
                    'updated_at' => now(),
                ]);
            }
            if (! empty($validated['items'])) {
                DB::table('order_adjustment_items')->where('adjustment_id', $id)->delete();
                foreach ($validated['items'] as $item) {
                    DB::table('order_adjustment_items')->insert([
                        'adjustment_id' => $id,
                        'product_id' => $item['product_id'],
                        'old_qty' => $item['old_qty'],
                        'new_qty' => $item['new_qty'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $this->log($id, $adjustment->adjust_no, '修改', auth('admin')->user(), '调整单内容已修改');
        });

        return $this->success(['id' => $id]);
    }

    public function destroy(int $id): JsonResponse
    {
        $adjustment = DB::table('order_adjustments')->find($id);
        if (! $adjustment) {
            return $this->notFound('订货调整单不存在');
        }
        if ($adjustment->status !== 'pending') {
            return $this->error('仅待审核的调整单可作废', 422);
        }

        DB::transaction(function () use ($adjustment) {
            DB::table('order_adjustments')->where('id', $adjustment->id)->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);
            $this->log((int) $adjustment->id, $adjustment->adjust_no, '作废', auth('admin')->user(), '调整单已作废');
        });

        return $this->success(['id' => (int) $adjustment->id]);
    }

    public function approve(int $id): JsonResponse
    {
        $adjustment = DB::table('order_adjustments')->find($id);
        if (! $adjustment) {
            return $this->notFound('订货调整单不存在');
        }
        if ($adjustment->status !== 'pending') {
            return $this->error('仅待审核的调整单可审核', 422);
        }

        DB::transaction(function () use ($adjustment) {
            $admin = auth('admin')->user();

            if ($adjustment->type === 'void') {
                DB::table('sales_orders')
                    ->where('id', $adjustment->order_id)
                    ->whereNotIn('status', ['cancelled'])
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
                $toStatus = 'cancelled';
            } else {
                $items = DB::table('order_adjustment_items')->where('adjustment_id', $adjustment->id)->get();
                foreach ($items as $item) {
                    $orderItem = DB::table('sales_order_items')
                        ->where('sales_order_id', $adjustment->order_id)
                        ->where('product_id', $item->product_id)
                        ->first();
                    if (! $orderItem) {
                        continue;
                    }
                    $amount = round((float) $item->new_qty * (float) $orderItem->price, 2);
                    DB::table('sales_order_items')
                        ->where('id', $orderItem->id)
                        ->update([
                            'quantity' => $item->new_qty,
                            'amount' => $amount,
                            'updated_at' => now(),
                        ]);
                }

                // 订单头重算：数量与金额均按明细行汇总
                $sums = DB::table('sales_order_items')
                    ->where('sales_order_id', $adjustment->order_id)
                    ->selectRaw('COALESCE(SUM(quantity),0) as total_qty, COALESCE(SUM(amount),0) as total_amount')
                    ->first();
                DB::table('sales_orders')->where('id', $adjustment->order_id)->update([
                    'total_qty' => $sums->total_qty,
                    'total_amount' => $sums->total_amount,
                    'updated_at' => now(),
                ]);
                $toStatus = DB::table('sales_orders')->where('id', $adjustment->order_id)->value('status') ?: 'pending';
            }

            DB::table('order_adjustments')->where('id', $adjustment->id)->update([
                'status' => 'approved',
                'applied_by' => $admin?->id,
                'applied_at' => now(),
                'updated_at' => now(),
            ]);

            $this->log((int) $adjustment->id, $adjustment->adjust_no, '审核通过', $admin, '调整已落地到源订单');
            // 源订单补一条留痕（日志在事务提交后写不回滚，这里同事务直接写即可）
            $this->logSourceOrder((int) $adjustment->order_id, '订货调整', $admin, '订货调整单 '.$adjustment->adjust_no.' 审核通过并落地', 'approved', $toStatus ?? null);
        });
        return $this->success(['id' => $id]);
    }

    public function reject(int $id): JsonResponse
    {
        $adjustment = DB::table('order_adjustments')->find($id);
        if (! $adjustment) {
            return $this->notFound('订货调整单不存在');
        }
        if ($adjustment->status !== 'pending') {
            return $this->error('仅待审核的调整单可驳回', 422);
        }

        DB::transaction(function () use ($adjustment) {
            DB::table('order_adjustments')->where('id', $adjustment->id)->update([
                'status' => 'rejected',
                'updated_at' => now(),
            ]);
            $this->log((int) $adjustment->id, $adjustment->adjust_no, '驳回', auth('admin')->user(), '调整单已驳回，源订单保持不变');
        });

        return $this->success(['id' => (int) $adjustment->id]);
    }

    /**
     * 校验调整明细与订单明细一致：每个 product_id 存在且 old_qty 等于当前数量。
     */
    private function assertItemsMatchOrder(int $orderId, array $items): bool
    {
        $current = DB::table('sales_order_items')
            ->where('sales_order_id', $orderId)
            ->pluck('quantity', 'product_id');

        foreach ($items as $item) {
            $pid = (int) $item['product_id'];
            if (! $current->has($pid)) {
                return false;
            }
            if (abs((float) $current->get($pid) - (float) $item['old_qty']) > 0.001) {
                return false;
            }
        }

        return true;
    }

    private function nextAdjustNo(): string
    {
        $prefix = 'ADJ'.now()->format('Ymd');
        $seq = (int) DB::table('order_adjustments')->where('adjust_no', 'like', $prefix.'%')->count() + 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function history(int $adjustmentId): array
    {
        return DB::table('order_operation_logs')
            ->where('order_type', 'order_adjustment')
            ->where('order_id', $adjustmentId)
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
                'from_status' => $log->from_status,
                'to_status' => $log->to_status,
                'created_at' => $log->created_at,
            ])
            ->all();
    }

    private function log(int $adjustmentId, string $adjustNo, string $action, $admin, string $detail, ?string $fromStatus = null, ?string $toStatus = null): void
    {
        DB::table('order_operation_logs')->insert([
            'order_id' => $adjustmentId,
            'order_no' => $adjustNo,
            'order_type' => 'order_adjustment',
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

    private function logSourceOrder(int $orderId, string $action, $admin, string $detail, ?string $fromStatus, ?string $toStatus): void
    {
        $order = DB::table('sales_orders')->find($orderId);
        if (! $order) {
            return;
        }
        DB::table('order_operation_logs')->insert([
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'order_type' => 'sales_order',
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
