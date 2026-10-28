<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * T5.1 客户订货核对（订货中心）
 *
 * 「客户订货量」vs「实际到货/发货量」逐行对比：
 *   diff      = actual_qty - quantity
 *   diff_rate = diff / quantity * 100（quantity=0 时：有实际数量记 100%，否则 0%）
 *   risk      = |diff_rate| > 30（±30% 阈值，前端标红 #F56C6C 加粗）
 *
 * 明细接口返回订单小计 + 明细行 + 全程操作留痕（order_operation_logs）。
 */
class OrderCheckController extends Controller
{
    use ResponseTrait;

    /** 差异率阈值（%）：|diff_rate| > 30 判定风险，前端标红 */
    private const RISK_THRESHOLD = 30.0;

    public function index(Request $request): JsonResponse
    {
        $keyword = trim((string) $request->query('keyword', ''));
        $onlyRisk = (bool) $request->boolean('only_risk');
        $startDate = $request->query('start_date', '');
        $endDate = $request->query('end_date', '');
        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        $query = DB::table('sales_order_items as soi')
            ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
            ->join('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
            ->select(
                'so.id as order_id',
                'so.order_no',
                'so.order_date',
                'so.status',
                'so.customer_id',
                'c.name as customer',
                'soi.product_id',
                'p.name as product',
                'soi.quantity as ordered_qty',
                'soi.actual_qty',
            );

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('so.order_no', 'like', "%{$keyword}%")
                    ->orWhere('c.name', 'like', "%{$keyword}%");
            });
        }
        if ($startDate !== '') {
            $query->whereDate('so.order_date', '>=', $startDate);
        }
        if ($endDate !== '') {
            $query->whereDate('so.order_date', '<=', $endDate);
        }

        $all = $query->orderByDesc('so.order_date')->orderBy('so.id')->orderBy('soi.id')->get();

        $rows = $all
            ->map(function ($r) {
                $ordered = (float) $r->ordered_qty;
                $actual = (float) $r->actual_qty;
                $diff = round($actual - $ordered, 2);
                $diffRate = $ordered > 0 ? round($diff / $ordered * 100, 2) : ($actual > 0 ? 100.0 : 0.0);

                return [
                    'order_id' => (int) $r->order_id,
                    'order_no' => $r->order_no,
                    'order_date' => (string) $r->order_date,
                    'status' => $r->status,
                    'customer_id' => (int) $r->customer_id,
                    'customer' => $r->customer,
                    'product_id' => (int) $r->product_id,
                    'product' => $r->product,
                    'ordered_qty' => $ordered,
                    'actual_qty' => $actual,
                    'diff' => $diff,
                    'diff_rate' => $diffRate,
                    'risk' => abs($diffRate) > self::RISK_THRESHOLD,
                ];
            })
            ->when($onlyRisk, fn ($collection) => $collection->where('risk', true))
            ->values();

        $total = $rows->count();
        $pageRows = $rows->slice(($page - 1) * $pageSize, $pageSize)->values();

        return $this->paginated(new LengthAwarePaginator(
            $pageRows,
            $total,
            $pageSize,
            $page,
        ));
    }

    public function show(int $orderId): JsonResponse
    {
        $order = DB::table('sales_orders as so')
            ->join('customers as c', 'c.id', '=', 'so.customer_id')
            ->where('so.id', $orderId)
            ->select('so.id', 'so.order_no', 'so.order_date', 'so.status', 'so.total_qty', 'so.total_amount', 'c.name as customer')
            ->first();
        if (! $order) {
            return $this->notFound('订货单不存在');
        }

        $items = DB::table('sales_order_items as soi')
            ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
            ->where('soi.sales_order_id', $orderId)
            ->orderBy('soi.id')
            ->get(['soi.id', 'soi.product_id', 'p.name as product', 'soi.quantity as ordered_qty', 'soi.actual_qty'])
            ->map(function ($r) {
                $ordered = (float) $r->ordered_qty;
                $actual = (float) $r->actual_qty;
                $diff = round($actual - $ordered, 2);
                $diffRate = $ordered > 0 ? round($diff / $ordered * 100, 2) : ($actual > 0 ? 100.0 : 0.0);

                return [
                    'product_id' => (int) $r->product_id,
                    'product' => $r->product,
                    'ordered_qty' => $ordered,
                    'actual_qty' => $actual,
                    'diff' => $diff,
                    'diff_rate' => $diffRate,
                    'risk' => abs($diffRate) > self::RISK_THRESHOLD,
                ];
            });

        $history = DB::table('order_operation_logs')
            ->where('order_type', 'sales_order')
            ->where('order_id', $orderId)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn ($log) => [
                'id' => (int) $log->id,
                'order_type' => $log->order_type,
                'action' => $log->action,
                'action_label' => $log->action_label,
                'operator' => $log->operator_name ?: $log->user_name,
                'detail' => $log->detail,
                'remark' => $log->remark,
                'from_status' => $log->from_status,
                'to_status' => $log->to_status,
                'created_at' => $log->created_at,
            ]);

        return $this->success(array_merge((array) $order, [
            'items' => $items,
            'history' => $history,
        ]));
    }
}
