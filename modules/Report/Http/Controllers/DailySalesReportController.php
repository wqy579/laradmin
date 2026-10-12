<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 销售日报（报表管理 → 销售日报）
 *
 * 单日销售快照：顶部 4 张汇总卡（销售额 / 订单数 / 客户数 / 客单价），
 * 明细按单据展开（一个销售订单一行），业务员列取自 sales_orders.salesman_id。
 *
 * 与销售报表 dimension=doc 的区别：销售报表是 13 维度通用报表（列定义随
 * dimension 动态下发），日报是连凯对标里的固定版式（固定列、固定汇总卡、
 * 默认今天、单日筛选），单独控制器避免把固定版式塞进通用维度切换里。
 *
 * 金额口径与销售报表一致：soi.amount 为 0 时按 quantity × price 回算，
 * 成本按 quantity × products.cost_price，保证同一笔销售在日报和销售报表
 * 里算出来是同一个数。
 */
class DailySalesReportController extends Controller
{
    /** 行金额表达式：soi.amount 为 0 时按 quantity × price 回算 */
    private const AMT = 'CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END';

    /** 行成本表达式：数量 × 商品成本价 */
    private const COST = 'soi.quantity * COALESCE(p.cost_price, 0)';

    /** 列定义（固定版式，不随 dimension 变化） */
    private const COLUMNS = [
        ['prop' => 'date', 'label' => '日期', 'width' => 100, 'align' => 'center', 'type' => 'date'],
        ['prop' => 'order_no', 'label' => '订单号', 'width' => 160, 'align' => 'left', 'type' => 'text'],
        ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
        ['prop' => 'qty', 'label' => '商品数量', 'width' => 90, 'align' => 'right', 'type' => 'number'],
        ['prop' => 'amount', 'label' => '销售额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'bold' => true],
        ['prop' => 'cost_amount', 'label' => '成本额', 'width' => 120, 'align' => 'right', 'type' => 'money'],
        ['prop' => 'profit', 'label' => '毛利', 'width' => 110, 'align' => 'right', 'type' => 'money', 'sign_color' => true],
        ['prop' => 'profit_rate', 'label' => '毛利率', 'width' => 90, 'align' => 'right', 'type' => 'percent'],
        ['prop' => 'salesman_name', 'label' => '业务员', 'width' => 100, 'align' => 'center', 'type' => 'text'],
    ];

    public function index(Request $request): JsonResponse
    {
        $params = $this->params($request);
        $paginator = $this->baseQuery($params)->paginate($params['page_size'], ['*'], 'page', $params['page']);

        return $this->success([
            ...ReportFilter::page($paginator),
            'columns' => self::COLUMNS,
            'summary' => $this->summary($params),
        ]);
    }

    public function export(Request $request)
    {
        $params = $this->params($request);
        $rows = $this->baseQuery($params)->limit(50000)->get();

        $body = array_map(fn ($r) => array_map(fn ($c) => $r->{$c['prop']} ?? '', self::COLUMNS), $rows);

        return ReportFilter::csvResponse(
            ReportFilter::csv('销售日报 - '.$params['date'], array_column(self::COLUMNS, 'label'), $body),
            'sales_daily_'.$params['date'].'.csv'
        );
    }

    private function params(Request $request): array
    {
        $today = date('Y-m-d');

        return [
            'date' => $request->input('date', $today) ?: $today,
            'warehouse_id' => $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null,
            'customer_id' => $request->filled('customer_id') ? (int) $request->input('customer_id') : null,
            'page' => max(1, (int) $request->input('page', 1)),
            'page_size' => min(500, max(1, (int) $request->input('page_size', 30))),
        ];
    }

    /** 按单据聚合：一个销售订单一行 */
    private function baseQuery(array $params)
    {
        $query = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->join('products as p', 'p.id', '=', 'soi.product_id')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
            ->whereBetween('so.order_date', [$params['date'], $params['date']]);

        if ($params['warehouse_id']) {
            $query->where('so.warehouse_id', $params['warehouse_id']);
        }
        if ($params['customer_id']) {
            $query->where('so.customer_id', $params['customer_id']);
        }

        return $query->select([
            'so.order_date as date',
            'so.order_no',
            DB::raw("COALESCE(c.name, '未知客户') as customer_name"),
            DB::raw('SUM(soi.quantity) as qty'),
            DB::raw('SUM('.self::AMT.') as amount'),
            DB::raw('SUM('.self::COST.') as cost_amount'),
            DB::raw('SUM('.self::AMT.') - SUM('.self::COST.') as profit'),
            DB::raw('ROUND(100 * (SUM('.self::AMT.') - SUM('.self::COST.')) / NULLIF(SUM('.self::AMT.'), 0), 2) as profit_rate'),
            DB::raw("COALESCE(sm.real_name, '未指派') as salesman_name"),
        ])->groupBy('so.id', 'so.order_date', 'so.order_no', 'c.name', 'sm.real_name')
            ->orderByDesc('so.id');
    }

    private function summary(array $params): array
    {
        $query = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->join('products as p', 'p.id', '=', 'soi.product_id')
            ->whereBetween('so.order_date', [$params['date'], $params['date']]);

        if ($params['warehouse_id']) {
            $query->where('so.warehouse_id', $params['warehouse_id']);
        }
        if ($params['customer_id']) {
            $query->where('so.customer_id', $params['customer_id']);
        }

        $row = $query->select([
            DB::raw('SUM('.self::AMT.') as amount'),
            DB::raw('SUM('.self::COST.') as cost_amount'),
            DB::raw('COUNT(DISTINCT so.id) as order_count'),
            DB::raw('COUNT(DISTINCT so.customer_id) as customer_count'),
        ])->first();

        $amount = ReportFilter::num($row->amount ?? 0);
        $cost = ReportFilter::num($row->cost_amount ?? 0);
        $orders = (int) ($row->order_count ?? 0);

        return [
            'amount' => $amount,
            'order_count' => $orders,
            'customer_count' => (int) ($row->customer_count ?? 0),
            'avg_price' => $orders > 0 ? round($amount / $orders, 2) : 0,
            'cost_amount' => $cost,
            'profit' => round($amount - $cost, 2),
        ];
    }
}
