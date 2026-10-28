<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * T5.3 订单移库（订货中心）
 *
 * 将销售订单的发货仓 from → to 调整（按明细行），移库后源订单 warehouse_id 切换为目标仓。
 *
 * 强约束：移库量 ≤ 订货量（sales_order_items.quantity）。
 *   - 后端：超量报 422、整单不落库（校验先行 + 事务）
 *   - 前端：同规则 max 限制，提交后仍以后端返回为准
 *
 * 路由：
 *   - GET    business/order-transfer          列表（keyword/status 过滤）
 *   - POST   business/order-transfer          执行移库（强约束校验）
 *   - GET    business/order-transfer/{id}     详情（明细 + 全程留痕 history）
 */
class OrderTransferController extends Controller
{
    use ResponseTrait;

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('order_transfers as ot')
            ->join('sales_orders as so', 'so.id', '=', 'ot.order_id')
            ->join('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('warehouses as wf', 'wf.id', '=', 'ot.from_warehouse_id')
            ->leftJoin('warehouses as wt', 'wt.id', '=', 'ot.to_warehouse_id')
            ->select(
                'ot.id',
                'ot.transfer_no',
                'ot.order_id',
                'so.order_no',
                'wf.name as from_warehouse',
                'wt.name as to_warehouse',
                'ot.transfer_date',
                'ot.reason',
                'ot.status',
                'c.name as customer',
                'ot.created_at',
            );

        if ($keyword = trim((string) $request->query('keyword', ''))) {
            $query->where(function ($q) use ($keyword) {
                $q->where('ot.transfer_no', 'like', "%{$keyword}%")
                    ->orWhere('so.order_no', 'like', "%{$keyword}%")
                    ->orWhere('c.name', 'like', "%{$keyword}%");
            });
        }
        if ($status = $request->query('status', '')) {
            $query->where('ot.status', $status);
        }

        return $this->paginated(
            $query->orderByDesc('ot.id')
                ->paginate(min(200, max(10, $request->integer('page_size', 20))))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|integer|exists:sales_orders,id',
            'to_warehouse_id' => 'required|integer|exists:warehouses,id',
            'transfer_date' => 'required|date',
            'reason' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty' => 'required|numeric|min:0.01',
        ]);

        $order = DB::table('sales_orders')->find($validated['order_id']);
        if (! $order) {
            return $this->notFound('订货单不存在');
        }
        if ((int) $validated['to_warehouse_id'] === (int) $order->warehouse_id) {
            return $this->error('目标仓库与订单当前仓库相同，无需移库', 422);
        }
        if (! in_array($order->status, ['draft', 'pending', 'approved'], true)) {
            return $this->error('仅待处理的订单可移库', 422);
        }

        // 强约束（后端）：逐行校验 移库量 ≤ 订货量；超量报 422，不落库
        $ordered = DB::table('sales_order_items')
            ->where('sales_order_id', $order->id)
            ->pluck('quantity', 'product_id');

        foreach ($validated['items'] as $item) {
            $pid = (int) $item['product_id'];
            $qty = (float) $item['qty'];
            if (! $ordered->has($pid)) {
                return $this->error('移库明细与订单明细不符（商品不在订单内）', 422);
            }
            if ($qty > (float) $ordered->get($pid) + 0.000001) {
                return $this->error(sprintf(
                    '移库量超过订货量：商品 #%s 移库 %s > 订货 %s，已拒绝且不落库',
                    $pid,
                    $qty,
                    (float) $ordered->get($pid),
                ), 422);
            }
        }

        $id = DB::transaction(function () use ($validated, $order) {
            $transferNo = $this->nextTransferNo();
            $admin = auth('admin')->user();

            $transferId = DB::table('order_transfers')->insertGetId([
                'transfer_no' => $transferNo,
                'order_id' => $order->id,
                'from_warehouse_id' => $order->warehouse_id,
                'to_warehouse_id' => $validated['to_warehouse_id'],
                'transfer_date' => $validated['transfer_date'],
                'reason' => $validated['reason'] ?? null,
                'status' => 'done',
                'created_by' => $admin?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($validated['items'] as $item) {
                DB::table('order_transfer_items')->insert([
                    'order_transfer_id' => $transferId,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 源订单切换仓库
            DB::table('sales_orders')->where('id', $order->id)->update([
                'warehouse_id' => $validated['to_warehouse_id'],
                'updated_at' => now(),
            ]);

            $qtySummary = collect($validated['items'])->sum(fn ($item) => (float) $item['qty']);
            $this->log($transferId, $transferNo, '移库', $admin, '订单移库：数量合计 '.$qtySummary.'，已切换发货仓');

            return $transferId;
        });

        return $this->success([
            'id' => $id,
            'transfer_no' => DB::table('order_transfers')->where('id', $id)->value('transfer_no'),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $transfer = DB::table('order_transfers as ot')
            ->join('sales_orders as so', 'so.id', '=', 'ot.order_id')
            ->leftJoin('warehouses as wf', 'wf.id', '=', 'ot.from_warehouse_id')
            ->leftJoin('warehouses as wt', 'wt.id', '=', 'ot.to_warehouse_id')
            ->where('ot.id', $id)
            ->select('ot.*', 'so.order_no', 'wf.name as from_warehouse', 'wt.name as to_warehouse')
            ->first();
        if (! $transfer) {
            return $this->notFound('移库单不存在');
        }

        $items = DB::table('order_transfer_items as oti')
            ->leftJoin('products as p', 'p.id', '=', 'oti.product_id')
            ->where('oti.order_transfer_id', $id)
            ->get(['oti.product_id', 'p.name as product', 'oti.qty']);

        $history = DB::table('order_operation_logs')
            ->where('order_type', 'order_transfer')
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

        return $this->success(array_merge((array) $transfer, [
            'items' => $items,
            'history' => $history,
        ]));
    }

    private function nextTransferNo(): string
    {
        $prefix = 'TRF'.now()->format('Ymd');
        $seq = (int) DB::table('order_transfers')->where('transfer_no', 'like', $prefix.'%')->count() + 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function log(int $transferId, string $transferNo, string $action, $admin, string $detail): void
    {
        DB::table('order_operation_logs')->insert([
            'order_id' => $transferId,
            'order_no' => $transferNo,
            'order_type' => 'order_transfer',
            'user_id' => $admin?->id,
            'user_name' => $admin?->username,
            'operator_id' => $admin?->id,
            'operator_name' => $admin?->username,
            'action' => $action,
            'action_label' => $action,
            'detail' => mb_substr($detail, 0, 500),
            'remark' => mb_substr($detail, 0, 500),
            'from_status' => null,
            'to_status' => 'done',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
