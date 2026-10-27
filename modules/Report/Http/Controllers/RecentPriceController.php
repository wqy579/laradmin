<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 模块一：最近价格（价格管理 → 最近价格）
 *
 * 每个商品一行，取它在筛选范围内「最近一次成交」的价格：
 * 日期取 MAX(order_date)，同一天多笔再取 MAX(id)（后录的后进，视为更新的一次）。
 *
 * 之所以不直接用 soi.id 最大：补录的历史单 id 更大但日期更早，
 * 按 id 取会取到老价格，与「最近」的语义相反。
 */
class RecentPriceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $params = ReportFilter::parse($request);
        $columns = $this->columns();
        $query = $this->buildQuery($params);
        $items = $query->limit(500)->get();

        $list = array_map(fn ($r) => (array) $r, $items->all());
        $summary = [
            'quantity' => ReportFilter::num($items->sum('quantity')),
            'amount' => ReportFilter::num($items->sum('amount')),
        ];

        return $this->success([
            'columns' => $columns,
            'list' => $list,
            'summary' => $summary,
            'total' => $items->count(),
        ]);
    }

    public function export(Request $request)
    {
        $params = ReportFilter::parse($request);
        $rows = $this->buildQuery($params)->limit(50000)->get();

        return ReportFilter::excelResponse(
            ReportFilter::excel('最近价格', $this->columns(), $rows),
            'recent_prices.xls'
        );
    }

    /** 列定义：与页面观感一致（宽度/类型/对齐），导出复用它保持列宽与表头底色一致 */
    private function columns(): array
    {
        return [
            ['prop' => 'product_code', 'label' => '商品编码', 'width' => 100],
            ['prop' => 'product_name', 'label' => '商品名称', 'width' => 160],
            ['prop' => 'spec', 'label' => '规格', 'width' => 120],
            ['prop' => 'unit', 'label' => '单位', 'width' => 60],
            ['prop' => 'last_sale_date', 'label' => '最近销售日期', 'width' => 120],
            ['prop' => 'order_no', 'label' => '最近销售单号', 'width' => 140],
            ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 140],
            ['prop' => 'quantity', 'label' => '销售数量', 'width' => 100, 'type' => 'number'],
            ['prop' => 'price', 'label' => '销售单价', 'width' => 110, 'type' => 'money'],
            ['prop' => 'amount', 'label' => '销售金额', 'width' => 120, 'type' => 'money'],
            ['prop' => 'warehouse_name', 'label' => '仓库', 'width' => 120],
        ];
    }

    /**
     * 主查询：只保留每个商品最近一次成交的那条明细
     */
    private function buildQuery(array $params)
    {
        return $this->baseQuery()
            ->joinSub($this->latestItems($params), 'latest', function ($join) {
                $join->on('latest.item_id', '=', 'soi.id');
            })
            ->select([
                'soi.id',
                'p.code as product_code',
                'p.name as product_name',
                'p.spec',
                'p.price_unit_small as unit',
                'so.order_date as last_sale_date',
                'so.order_no',
                'c.code as customer_code',
                'c.name as customer_name',
                'soi.quantity',
                DB::raw('CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END as price'),
                DB::raw('CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END as amount'),
                'w.name as warehouse_name',
            ])
            ->orderBy('so.order_date', 'desc')
            ->orderBy('soi.id', 'desc');
    }

    /**
     * 筛选范围内的基础数据集（别名：so 订单、soi 明细、p 商品、c 客户、w 仓库）
     */
    private function baseQuery()
    {
        return DB::table('sales_order_items as soi')
            ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
            ->join('products as p', 'p.id', '=', 'soi.product_id')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'so.warehouse_id')
            ->whereNull('so.original_order_id');
    }

    /**
     * 每个商品最近一次成交的明细 id
     *
     * 两跳：先按商品取 MAX(order_date)，再在同日里取 MAX(id)。
     * 合成一步（MAX(CONCAT(date, id))）要拼字符串，且 date 与 id 的排序方向不同，
     * 写得对不如写得清楚，这里宁可多一跳。
     */
    private function latestItems(array $params)
    {
        $latestDates = $this->scoped($params)
            ->select('soi.product_id', DB::raw('MAX(so.order_date) as latest_date'))
            ->groupBy('soi.product_id');

        return $this->scoped($params)
            ->joinSub($latestDates, 'ld', function ($join) {
                $join->on('ld.product_id', '=', 'soi.product_id')
                    ->on('ld.latest_date', '=', 'so.order_date');
            })
            ->select('soi.product_id', DB::raw('MAX(soi.id) as item_id'))
            ->groupBy('soi.product_id');
    }

    /** 带筛选条件的基础数据集 */
    private function scoped(array $params)
    {
        $query = $this->baseQuery();

        // 最近价格只按销售日期（申报日期）筛选，页面上的开始/结束日期就作用于它
        ReportFilter::applyDateRange($query, array_merge($params, ['date_type' => 'declare']));
        ReportFilter::applyDimensions($query, $params);

        if (! empty($params['product_code'])) {
            $query->where('p.code', 'like', '%'.$params['product_code'].'%');
        }
        if (! empty($params['customer_code'])) {
            $query->where('c.code', 'like', '%'.$params['customer_code'].'%');
        }
        if ($params['warehouse_ids']) {
            $query->whereIn('so.warehouse_id', $params['warehouse_ids']);
        }

        return $query;
    }
}
