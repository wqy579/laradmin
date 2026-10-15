<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 退货汇总单（只读）：客户退货 sales_returns ∪ 车销退货 van_return_orders（均 status=approved），
 * 按 退货类型 × 渠道大类 × 渠道小类 × 仓库 × 退货日期 聚合。
 * 客户缺失或渠道为空显示「未分类」；车销退货的仓库取 vehicle_warehouse_id（车上仓）。
 * 无导出/打印，list 全量返回由前端本地分页，totals 后端聚合。
 */
class ReturnSummaryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $dateStart = trim((string) $request->query('date_start'));
        $dateEnd = trim((string) $request->query('date_end'));
        $returnType = trim((string) $request->query('return_type'));
        $channelCategory = trim((string) $request->query('channel_category'));
        $channelSubCategory = trim((string) $request->query('channel_sub_category'));

        if (($dateStart !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStart))
            || ($dateEnd !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEnd))) {
            return $this->error('日期格式须为 YYYY-MM-DD');
        }
        if ($dateStart !== '' && $dateEnd !== '' && $dateStart > $dateEnd) {
            return $this->error('date_start 不能晚于 date_end');
        }
        if ($returnType !== '' && ! in_array($returnType, ['customer', 'van'], true)) {
            return $this->error('return_type 仅支持 customer/van');
        }

        $warehouseId = null;
        $warehouseRaw = $request->query('warehouse_id');
        if ($warehouseRaw !== null && $warehouseRaw !== '') {
            if (! ctype_digit((string) $warehouseRaw)) {
                return $this->error('warehouse_id 须为整数');
            }
            $warehouseId = (int) $warehouseRaw;
        }

        $rows = DB::query()->fromSub($this->unionQuery($returnType, $dateStart, $dateEnd, $warehouseId), 'u')
            ->selectRaw('u.return_type, u.channel_category, u.channel_sub_category, u.warehouse_id, u.warehouse_name, u.return_date')
            ->selectRaw('COUNT(DISTINCT u.doc_id) as return_count')
            ->selectRaw('SUM(u.total_qty) as return_qty')
            ->selectRaw('SUM(u.total_amount) as return_amount')
            ->when($channelCategory !== '', fn ($q) => $q->where('u.channel_category', $channelCategory))
            ->when($channelSubCategory !== '', fn ($q) => $q->where('u.channel_sub_category', $channelSubCategory))
            ->groupBy('u.return_type', 'u.channel_category', 'u.channel_sub_category', 'u.warehouse_id', 'u.warehouse_name', 'u.return_date')
            ->orderByDesc('u.return_date')
            ->orderBy('u.return_type')
            ->orderBy('u.channel_category')
            ->get();

        $list = $rows->map(fn ($row) => [
            'return_type' => (string) $row->return_type,
            'return_type_label' => $row->return_type === 'van' ? '车辆退货' : '客户退货',
            'channel_category' => (string) $row->channel_category,
            'channel_sub_category' => (string) $row->channel_sub_category,
            'warehouse_id' => (int) $row->warehouse_id,
            'warehouse_name' => (string) ($row->warehouse_name ?? ''),
            'return_date' => (string) $row->return_date,
            'return_count' => (int) $row->return_count,
            'return_qty' => round((float) $row->return_qty, 3),
            'return_amount' => round((float) $row->return_amount, 2),
        ])->values();

        $totals = ['return_count' => 0, 'return_qty' => 0.0, 'return_amount' => 0.0];
        foreach ($list as $row) {
            $totals['return_count'] += $row['return_count'];
            $totals['return_qty'] += $row['return_qty'];
            $totals['return_amount'] += $row['return_amount'];
        }
        $totals['return_qty'] = round($totals['return_qty'], 3);
        $totals['return_amount'] = round($totals['return_amount'], 2);

        return $this->success([
            'list' => $list->all(),
            'totals' => $totals,
        ]);
    }

    /**
     * 两源 UNION ALL，列顺序严格对齐；日期/仓库过滤下推到各源（走索引），
     * 渠道过滤在外层聚合前应用（「未分类」已在源侧 COALESCE 落地，可精确匹配）。
     */
    private function unionQuery(string $returnType, string $dateStart, string $dateEnd, ?int $warehouseId): Builder
    {
        $customerReturns = DB::table('sales_returns as sr')
            ->leftJoin('customers as c', 'c.id', '=', 'sr.customer_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sr.warehouse_id')
            ->where('sr.status', 'approved')
            ->when($dateStart !== '', fn ($q) => $q->where('sr.return_date', '>=', $dateStart))
            ->when($dateEnd !== '', fn ($q) => $q->where('sr.return_date', '<=', $dateEnd))
            ->when($warehouseId !== null, fn ($q) => $q->where('sr.warehouse_id', $warehouseId))
            ->selectRaw("sr.id as doc_id, 'customer' as return_type, sr.warehouse_id as warehouse_id, w.name as warehouse_name, sr.customer_id as customer_id, sr.return_date as return_date, sr.total_qty as total_qty, sr.total_amount as total_amount")
            ->addSelect(DB::raw('COALESCE(c.channel_category, "未分类") as channel_category'))
            ->addSelect(DB::raw('COALESCE(c.channel_sub_category, "未分类") as channel_sub_category'));

        $vanReturns = DB::table('van_return_orders as vo')
            ->leftJoin('customers as c', 'c.id', '=', 'vo.customer_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'vo.vehicle_warehouse_id')
            ->where('vo.status', 'approved')
            ->when($dateStart !== '', fn ($q) => $q->where('vo.return_date', '>=', $dateStart))
            ->when($dateEnd !== '', fn ($q) => $q->where('vo.return_date', '<=', $dateEnd))
            ->when($warehouseId !== null, fn ($q) => $q->where('vo.vehicle_warehouse_id', $warehouseId))
            ->selectRaw("vo.id as doc_id, 'van' as return_type, vo.vehicle_warehouse_id as warehouse_id, w.name as warehouse_name, vo.customer_id as customer_id, vo.return_date as return_date, vo.total_qty as total_qty, vo.total_amount as total_amount")
            ->addSelect(DB::raw('COALESCE(c.channel_category, "未分类") as channel_category'))
            ->addSelect(DB::raw('COALESCE(c.channel_sub_category, "未分类") as channel_sub_category'));

        if ($returnType === 'customer') {
            return $customerReturns;
        }
        if ($returnType === 'van') {
            return $vanReturns;
        }

        return $customerReturns->unionAll($vanReturns);
    }
}
