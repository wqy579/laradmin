<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 销售利润报表（财务管理 → 月度利润 → 销售利润 tab）
 *
 * 口径：净毛利 = 销售额 − 成本 − 退货金额。退货来自 sales_returns（销售退货，
 * 有 order_id 指回原销售订单），与采购退货 returns（supplier_id）是两张表，别混。
 *
 * 退货金额按「原订单 × 商品」匹配：sales_return_items.order_qty/return_price 冗余了
 * 原订单信息，但最稳的是 join 回原订单再 join 回 sales_order_items，取原订单行的
 * 金额作为退货冲减口径（退货按原单价退，成本同步冲回）。
 */
class SalesProfitReportController extends Controller
{
    private const AMT = 'CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END';

    private const COST = 'soi.quantity * COALESCE(p.cost_price, 0)';

    private const COLUMNS = [
        ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
        ['prop' => 'product_name', 'label' => '商品名称', 'width' => 200, 'align' => 'left', 'type' => 'text'],
        ['prop' => 'qty', 'label' => '销售数量', 'width' => 90, 'align' => 'right', 'type' => 'number'],
        ['prop' => 'amount', 'label' => '销售额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'bold' => true],
        ['prop' => 'cost_amount', 'label' => '成本额', 'width' => 120, 'align' => 'right', 'type' => 'money'],
        ['prop' => 'profit', 'label' => '毛利额', 'width' => 110, 'align' => 'right', 'type' => 'money', 'sign_color' => true],
        ['prop' => 'profit_rate', 'label' => '毛利率', 'width' => 90, 'align' => 'right', 'type' => 'percent'],
        ['prop' => 'return_amount', 'label' => '退货金额', 'width' => 110, 'align' => 'right', 'type' => 'money'],
        ['prop' => 'net_profit', 'label' => '净毛利', 'width' => 120, 'align' => 'right', 'type' => 'money', 'bold' => true, 'sign_color' => true],
    ];

    public function index(Request $request): JsonResponse
    {
        $params = $this->params($request);
        $rows = $this->rows($params);

        return $this->success([
            'columns' => self::COLUMNS,
            'list' => $rows,
            'total' => count($rows),
            'page' => 1,
            'page_size' => count($rows),
            'summary' => $this->summary($rows),
        ]);
    }

    public function export(Request $request)
    {
        $params = $this->params($request);
        $rows = $this->rows($params);
        $body = array_map(fn ($r) => array_map(fn ($c) => $r[$c['prop']] ?? '', self::COLUMNS), $rows);

        return ReportFilter::csvResponse(
            ReportFilter::csv('销售利润报表', array_column(self::COLUMNS, 'label'), $body),
            'sales_profit_'.$params['start_date'].'_'.$params['end_date'].'.csv'
        );
    }

    private function params(Request $request): array
    {
        $start = $request->input('start_date', date('Y-m-01'));
        $end = $request->input('end_date', date('Y-m-d'));

        return [
            'start_date' => $start ?: date('Y-m-01'),
            'end_date' => $end ?: date('Y-m-d'),
            'customer_id' => $request->filled('customer_id') ? (int) $request->input('customer_id') : null,
            'salesman_id' => $request->filled('salesman_id') ? (int) $request->input('salesman_id') : null,
            'page' => max(1, (int) $request->input('page', 1)),
            'page_size' => min(500, max(1, (int) $request->input('page_size', 30))),
        ];
    }

    /**
     * 客户 × 商品 维度的利润明细
     *
     * 销售主表 + 退货子查询（原订单 × 商品匹配），避免 join 放大销售额行。
     */
    private function rows(array $params): array
    {
        $query = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->join('products as p', 'p.id', '=', 'soi.product_id')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->whereBetween('so.order_date', [$params['start_date'], $params['end_date']]);

        if ($params['customer_id']) {
            $query->where('so.customer_id', $params['customer_id']);
        }
        if ($params['salesman_id']) {
            $query->where('so.salesman_id', $params['salesman_id']);
        }

        $aggregated = $query->select([
            DB::raw("COALESCE(c.name, '未知客户') as customer_name"),
            'p.name as product_name',
            DB::raw('SUM(soi.quantity) as qty'),
            DB::raw('SUM('.self::AMT.') as amount'),
            DB::raw('SUM('.self::COST.') as cost_amount'),
        ])->groupBy('c.name', 'p.id', 'p.name')->orderBy('p.name')->get();

        $returnMap = $this->returnAmountsByOrderProduct($params);

        $rows = [];
        foreach ($aggregated as $row) {
            $amount = ReportFilter::num($row->amount ?? 0);
            $cost = ReportFilter::num($row->cost_amount ?? 0);
            $profit = round($amount - $cost, 2);
            // 退货按商品聚合（sales_return_items 没冗余客户，无法按客户×商品拆分）
            $returnAmount = ReportFilter::num($returnMap->get($row->product_name) ?? 0);

            $rows[] = [
                'customer_name' => $row->customer_name,
                'product_name' => $row->product_name,
                'qty' => ReportFilter::num($row->qty ?? 0),
                'amount' => $amount,
                'cost_amount' => $cost,
                'profit' => $profit,
                'profit_rate' => $amount > 0 ? round($profit / $amount * 100, 2) : 0,
                'return_amount' => $returnAmount,
                'net_profit' => round($profit - $returnAmount, 2),
            ];
        }

        return $rows;
    }

    /**
     * 退货金额按商品聚合
     *
     * sales_return_items 冗余了 product_id/product_name/return_amount，
     * 直接按 return_id → sales_returns 过滤日期，group by 商品即可。
     */
    private function returnAmountsByOrderProduct(array $params): Collection
    {
        return DB::table('sales_return_items as sri')
            ->join('sales_returns as sr', 'sr.id', '=', 'sri.return_id')
            ->whereBetween('sr.return_date', [$params['start_date'], $params['end_date']])
            // 只算真实发生的退货：approved 与 auto_return（系统按 controller 里对
            // auto_return 的判等视为 approved）。cancelled 已冲回、draft/pending 未生效，
            // 都不该进退货金额。
            ->whereIn('sr.status', ['approved', 'auto_return'])
            ->select([
                'sri.product_name',
                DB::raw('SUM(sri.return_amount) as return_amount'),
            ])->groupBy('sri.product_name')->pluck('return_amount', 'product_name');
    }

    private function summary(array $rows): array
    {
        $income = ReportFilter::num(array_sum(array_column($rows, 'amount')));
        $cost = ReportFilter::num(array_sum(array_column($rows, 'cost_amount')));
        $returns = ReportFilter::num(array_sum(array_column($rows, 'return_amount')));
        $gross = round($income - $cost, 2);
        $net = round($income - $cost - $returns, 2);

        return [
            'income' => $income,
            'cost' => $cost,
            'profit' => $gross,
            'profit_rate' => $income > 0 ? round($gross / $income * 100, 2) : 0,
            'return_amount' => $returns,
            'net_profit' => $net,
        ];
    }
}
