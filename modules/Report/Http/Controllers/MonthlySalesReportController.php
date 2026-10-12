<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 销售月报（报表管理 → 销售月报）
 *
 * 整月逐日趋势：一行一天（1~31 日），汇总卡给月销售额 / 月订单数 / 月客户数 /
 * 月毛利率，明细表带环比增长（当日销售额相对前一日）。
 *
 * 环比增长 =（当日 − 前一日）/ 前一日 × 100%，前一日为 0 时不显示（无法定义增长率），
 * 前端按正负渲染 ↑绿 / ↓红。
 */
class MonthlySalesReportController extends Controller
{
    private const AMT = 'CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END';

    private const COST = 'soi.quantity * COALESCE(p.cost_price, 0)';

    /** 列定义：星期由前端按日期算，环比增长由后端算好传标签 */
    private const COLUMNS = [
        ['prop' => 'date', 'label' => '日期', 'width' => 100, 'align' => 'center', 'type' => 'date'],
        ['prop' => 'weekday', 'label' => '星期', 'width' => 70, 'align' => 'center', 'type' => 'text'],
        ['prop' => 'amount', 'label' => '销售额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'bold' => true],
        ['prop' => 'order_count', 'label' => '订单数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
        ['prop' => 'customer_count', 'label' => '客户数', 'width' => 80, 'align' => 'right', 'type' => 'int'],
        ['prop' => 'avg_price', 'label' => '客单价', 'width' => 100, 'align' => 'right', 'type' => 'money'],
        ['prop' => 'growth_label', 'label' => '环比增长', 'width' => 100, 'align' => 'right', 'type' => 'text'],
    ];

    public function index(Request $request): JsonResponse
    {
        $params = $this->params($request);
        $rows = $this->dailyRows($params);

        return $this->success([
            'columns' => self::COLUMNS,
            'list' => $rows,
            'total' => count($rows),
            'page' => 1,
            'page_size' => count($rows),
            'summary' => $this->summary($params, $rows),
        ]);
    }

    public function export(Request $request)
    {
        $params = $this->params($request);
        $rows = $this->dailyRows($params);
        $body = array_map(fn ($r) => array_map(fn ($c) => $r[$c['prop']] ?? '', self::COLUMNS), $rows);

        return ReportFilter::csvResponse(
            ReportFilter::csv('销售月报 - '.$params['month'], array_column(self::COLUMNS, 'label'), $body),
            'sales_monthly_'.$params['month'].'.csv'
        );
    }

    private function params(Request $request): array
    {
        $month = $request->input('month', date('Y-m')) ?: date('Y-m');
        $month = substr($month, 0, 7);
        $dim = (int) date('t', strtotime($month.'-01'));
        $endDay = $dim;

        // 未来日期不算：今天之后的天没发生，环比也没有意义
        if ($month === date('Y-m')) {
            $endDay = min($dim, (int) date('d'));
        }

        return [
            'month' => $month,
            'start' => $month.'-01',
            'end' => $month.'-'.str_pad((string) $endDay, 2, '0', STR_PAD_LEFT),
            'end_day' => $endDay,
            'salesman_id' => $request->filled('salesman_id') ? (int) $request->input('salesman_id') : null,
            'customer_level_id' => $request->filled('customer_level_id') ? (int) $request->input('customer_level_id') : null,
        ];
    }

    /**
     * 逐日聚合 + 环比
     *
     * 一次性把整月拉出来在 PHP 里算环比：天数最多 31，SQL 里写窗口函数或
     * 自关联反而难读，且 SQLite/MySQL 都要验证两套语法。
     */
    private function dailyRows(array $params): array
    {
        $query = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->join('products as p', 'p.id', '=', 'soi.product_id')
            ->whereBetween('so.order_date', [$params['start'], $params['end']]);

        if ($params['salesman_id']) {
            $query->where('so.salesman_id', $params['salesman_id']);
        }
        if ($params['customer_level_id']) {
            $query->whereExists(function ($sub) use ($params) {
                $sub->from('customers as c')
                    ->whereColumn('c.id', 'so.customer_id')
                    ->where('c.level_id', $params['customer_level_id']);
            });
        }

        $aggregated = $query->select([
            'so.order_date as date',
            DB::raw('SUM('.self::AMT.') as amount'),
            DB::raw('SUM('.self::COST.') as cost_amount'),
            DB::raw('COUNT(DISTINCT so.id) as order_count'),
            DB::raw('COUNT(DISTINCT so.customer_id) as customer_count'),
        ])->groupBy('so.order_date')->orderBy('so.order_date')->get()->keyBy('date');

        $weekdays = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];
        $rows = [];
        $prevAmount = null;

        for ($day = 1; $day <= $params['end_day']; $day++) {
            $date = $params['month'].'-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT);
            $row = $aggregated->get($date);
            $amount = ReportFilter::num($row->amount ?? 0);
            $cost = ReportFilter::num($row->cost_amount ?? 0);
            $orders = (int) ($row->order_count ?? 0);

            $growth = null;
            $growthLabel = '';
            if ($prevAmount !== null) {
                if ($prevAmount > 0) {
                    $growth = ($amount - $prevAmount) / $prevAmount * 100;
                    $growthLabel = ($growth >= 0 ? '↑' : '↓').round(abs($growth), 1).'%';
                } else {
                    $growthLabel = $amount > 0 ? '↑--' : '--';
                }
            }

            $rows[] = [
                'date' => $date,
                'weekday' => $weekdays[(int) date('w', strtotime($date))],
                'amount' => $amount,
                'order_count' => $orders,
                'customer_count' => (int) ($row->customer_count ?? 0),
                'avg_price' => $orders > 0 ? round($amount / $orders, 2) : 0,
                'growth_rate' => $growth,
                'growth_label' => $growthLabel,
            ];

            $prevAmount = $amount;
        }

        return $rows;
    }

    private function summary(array $params, array $rows): array
    {
        $amount = ReportFilter::num(array_sum(array_column($rows, 'amount')));

        // 成本与去重客户数在明细行里没有，单独查一次（明细只下发销售额/订单数/客户数）
        $row = $this->costAndCustomerQuery($params)->select([
            DB::raw('SUM('.self::COST.') as cost_amount'),
            DB::raw('COUNT(DISTINCT so.customer_id) as customer_count'),
        ])->first();

        $cost = ReportFilter::num($row->cost_amount ?? 0);
        $profit = round($amount - $cost, 2);

        return [
            'amount' => $amount,
            'order_count' => array_sum(array_column($rows, 'order_count')),
            'customer_count' => (int) ($row->customer_count ?? 0),
            'profit' => $profit,
            'profit_rate' => $amount > 0 ? round($profit / $amount * 100, 2) : 0,
        ];
    }

    /** 成本 / 客户数聚合用的基础查询（与 dailyRows 共用一套筛选语义） */
    private function costAndCustomerQuery(array $params)
    {
        $query = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->join('products as p', 'p.id', '=', 'soi.product_id')
            ->whereBetween('so.order_date', [$params['start'], $params['end']]);

        if ($params['salesman_id']) {
            $query->where('so.salesman_id', $params['salesman_id']);
        }
        if ($params['customer_level_id']) {
            $query->whereExists(function ($sub) use ($params) {
                $sub->from('customers as c')
                    ->whereColumn('c.id', 'so.customer_id')
                    ->where('c.level_id', $params['customer_level_id']);
            });
        }

        return $query;
    }
}
