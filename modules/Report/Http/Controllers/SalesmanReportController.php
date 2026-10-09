<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 模块四：业务员报表（报表管理 → 业务员报表）
 *
 * 与销售报表共用筛选条件，区别只在分组键上多一个业务员。
 *
 * 退货金额的归属口径：sales_returns 上没有业务员字段，靠原单 order_id 回挂销售订单，
 * 因此「退货」算在原单的业务员头上——这也是业务上认的口径（谁的卖出去谁负责退）。
 * 只有「按业务员」这一维度出退货/净销售列：再往下钻到客户/商品，
 * 退货单只有明细商品、没有明细级原价，硬拆会拆出负数毛利，宁可不给。
 */
class SalesmanReportController extends Controller
{
    private const DIMENSIONS = [
        'salesman' => '按业务员',
        'salesman_customer' => '按业务员客户',
        'salesman_product' => '按业务员商品',
        'salesman_customer_product' => '按业务员客户商品',
        'salesman_brand_product' => '按业务员品牌商品',
    ];

    public function index(Request $request): JsonResponse
    {
        $params = ReportFilter::parse($request);
        $dimension = (string) ($params['dimension'] ?: 'salesman');
        if (! array_key_exists($dimension, self::DIMENSIONS)) {
            $dimension = 'salesman';
        }

        $paginator = $this->aggregate($params, $dimension)
            ->paginate($params['page_size'], ['*'], 'page', $params['page']);

        $list = collect($paginator->items());

        // 退货金额、平均客单价只在「按业务员」维度回填（这两个指标的定义本身就只在人这一层成立）
        if ($dimension === 'salesman') {
            $returns = $this->returnAmounts($params);
            $list = $list->map(function ($row) use ($returns) {
                $row->return_amount = ReportFilter::num($returns[$row->salesman_id] ?? 0);
                $row->net_amount = round((float) $row->amount - (float) $row->return_amount, 2);
                $row->avg_customer_amount = $row->customer_count > 0
                    ? round((float) $row->amount / (int) $row->customer_count, 2)
                    : 0;

                return $row;
            });
        }

        $data = ReportFilter::page($paginator);
        $data['list'] = $list->all();
        $data['dimension'] = $dimension;
        $data['columns'] = $this->columns($dimension, $params);
        $data['summary'] = $this->summary($params, $dimension, $list);

        return $this->success($data);
    }

    public function export(Request $request)
    {
        $params = ReportFilter::parse($request);
        $dimension = (string) ($params['dimension'] ?: 'salesman');
        $columns = $this->columns($dimension, $params);
        $rows = $this->aggregate($params, $dimension)->limit(50000)->get();

        if ($dimension === 'salesman') {
            $returns = $this->returnAmounts($params);
            $rows = $rows->map(function ($row) use ($returns) {
                $row->return_amount = ReportFilter::num($returns[$row->salesman_id] ?? 0);
                $row->net_amount = round((float) $row->amount - (float) $row->return_amount, 2);
                $row->avg_customer_amount = $row->customer_count > 0
                    ? round((float) $row->amount / (int) $row->customer_count, 2)
                    : 0;

                return $row;
            });
        }

        $body = [];
        foreach ($rows as $row) {
            $body[] = array_map(fn ($c) => $row->{$c['prop']} ?? '', $columns);
        }

        return ReportFilter::csvResponse(
            ReportFilter::csv('业务员报表 - '.(self::DIMENSIONS[$dimension] ?? ''), array_column($columns, 'label'), $body),
            'salesman_report_'.$dimension.'.csv'
        );
    }

    private function baseQuery(array $params)
    {
        $query = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->join('products as p', 'p.id', '=', 'soi.product_id')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'so.warehouse_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
            ->leftJoin('employees as e', 'e.user_id', '=', 'so.salesman_id');

        ReportFilter::applyDateRange($query, $params);
        ReportFilter::applyDimensions($query, $params);
        ReportFilter::applyOrderFilters($query, $params);

        return $query;
    }

    private function aggregate(array $params, string $dimension)
    {
        $query = $this->baseQuery($params);

        $measures = [
            DB::raw('SUM(soi.quantity) as qty'),
            DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) as amount'),
            DB::raw('SUM(soi.quantity * COALESCE(p.cost_price, 0)) as cost_amount'),
            DB::raw('SUM(CASE WHEN soi.amount > 0 THEN soi.amount ELSE soi.quantity * (CASE WHEN soi.price > 0 THEN soi.price ELSE soi.price_small END) END) - SUM(soi.quantity * COALESCE(p.cost_price, 0)) as profit'),
            DB::raw('COUNT(DISTINCT so.id) as order_count'),
            DB::raw('COUNT(DISTINCT so.customer_id) as customer_count'),
            DB::raw('COUNT(DISTINCT soi.product_id) as product_count'),
        ];

        $salesman = [
            'so.salesman_id',
            DB::raw('COALESCE(e.code, sm.username, "—") as salesman_code'),
            DB::raw('COALESCE(sm.real_name, sm.username, "未指派") as salesman_name'),
        ];

        $groupBy = [];

        switch ($dimension) {
            case 'salesman':
                $query->select(array_merge($salesman, $measures));
                $groupBy = ['so.salesman_id', 'e.code', 'sm.username', 'sm.real_name'];
                break;

            case 'salesman_customer':
                $query->select(array_merge($salesman, [
                    'c.id as customer_id', 'c.code as customer_code', 'c.name as customer_name',
                ], $measures));
                $groupBy = ['so.salesman_id', 'e.code', 'sm.username', 'sm.real_name', 'c.id', 'c.code', 'c.name'];
                break;

            case 'salesman_product':
                $query->select(array_merge($salesman, [
                    'p.id as product_id', 'p.code as product_code', 'p.name as product_name',
                    'p.spec', 'p.price_unit_small as unit',
                ], $measures));
                $groupBy = ['so.salesman_id', 'e.code', 'sm.username', 'sm.real_name', 'p.id', 'p.code', 'p.name', 'p.spec', 'p.price_unit_small'];
                break;

            case 'salesman_customer_product':
                $query->select(array_merge($salesman, [
                    'c.id as customer_id', 'c.code as customer_code', 'c.name as customer_name',
                    'p.id as product_id', 'p.code as product_code', 'p.name as product_name',
                    'p.spec', 'p.price_unit_small as unit',
                ], $measures));
                $groupBy = ['so.salesman_id', 'e.code', 'sm.username', 'sm.real_name', 'c.id', 'c.code', 'c.name', 'p.id', 'p.code', 'p.name', 'p.spec', 'p.price_unit_small'];
                break;

            case 'salesman_brand_product':
                $query->select(array_merge($salesman, [
                    DB::raw('COALESCE(b.name, "未指定品牌") as brand_name'),
                    'p.id as product_id', 'p.code as product_code', 'p.name as product_name',
                    'p.spec', 'p.price_unit_small as unit',
                ], $measures));
                $groupBy = ['so.salesman_id', 'e.code', 'sm.username', 'sm.real_name', 'b.name', 'p.id', 'p.code', 'p.name', 'p.spec', 'p.price_unit_small'];
                break;
        }

        foreach ($groupBy as $column) {
            $query->groupBy($column);
        }

        return $query->orderByDesc('amount');
    }

    /** 按业务员汇总退货金额（salesman_id => amount） */
    private function returnAmounts(array $params): array
    {
        $start = $params['start_date'] ?: '1970-01-01';
        $end = $params['end_date'] ?: '2999-12-31';

        return DB::table('sales_returns as sr')
            ->join('sales_orders as so', 'so.id', '=', 'sr.order_id')
            ->whereBetween('sr.return_date', [$start, $end])
            // 只统计真实退货：cancelled 已取消、auto_return 是配送取消的自动回补
            // （货未交付、无财务影响），都不应算作业务员名下退货金额
            ->whereNotIn('sr.status', ['cancelled', 'auto_return'])
            ->groupBy('so.salesman_id')
            ->pluck(DB::raw('SUM(sr.total_amount)'), 'so.salesman_id')
            ->all();
    }

    private function summary(array $params, string $dimension, $list): array
    {
        // 合计必须覆盖整批结果而不是当前页：分页只截断了行，数字跟着翻页变会直接误导。
        $total = $this->aggregate($params, $dimension)->get();
        $amount = (float) $total->sum('amount');
        $cost = (float) $total->sum('cost_amount');
        $qty = (float) $total->sum('qty');
        $returnAmount = array_sum($this->returnAmounts($params));

        return [
            'qty' => ReportFilter::num($qty),
            'amount' => ReportFilter::num($amount),
            'cost_amount' => ReportFilter::num($cost),
            'profit' => ReportFilter::num($amount - $cost),
            'profit_rate' => $amount > 0 ? round(($amount - $cost) / $amount * 100, 2) : 0,
            'return_amount' => ReportFilter::num($returnAmount),
            'net_amount' => ReportFilter::num($amount - $returnAmount),
            'order_count' => (int) $total->sum('order_count'),
        ];
    }

    private function columns(string $dimension, array $params): array
    {
        $withPrice = (bool) $params['with_price'];

        $salesman = [
            ['prop' => 'salesman_code', 'label' => '业务员编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
            ['prop' => 'salesman_name', 'label' => '业务员名称', 'width' => 100, 'align' => 'left', 'type' => 'text'],
        ];

        $qty = ['prop' => 'qty', 'label' => '销售数量', 'width' => 110, 'align' => 'right', 'type' => 'number'];
        $amount = ['prop' => 'amount', 'label' => '销售金额', 'width' => 130, 'align' => 'right', 'type' => 'money', 'bold' => true];
        $cost = ['prop' => 'cost_amount', 'label' => '成本金额', 'width' => 120, 'align' => 'right', 'type' => 'money'];
        $profit = ['prop' => 'profit', 'label' => '毛利', 'width' => 120, 'align' => 'right', 'type' => 'money', 'sign_color' => true];
        $rate = ['prop' => 'profit_rate', 'label' => '毛利率', 'width' => 90, 'align' => 'right', 'type' => 'percent'];
        $priceColumns = $withPrice ? [$cost, $profit, $rate] : [];

        if ($dimension === 'salesman') {
            return array_merge($salesman, [
                ['prop' => 'order_count', 'label' => '销售单据数', 'width' => 100, 'align' => 'right', 'type' => 'int'],
                ['prop' => 'customer_count', 'label' => '销售客户数', 'width' => 100, 'align' => 'right', 'type' => 'int'],
                ['prop' => 'product_count', 'label' => '销售商品数', 'width' => 100, 'align' => 'right', 'type' => 'int'],
            ], [$qty, $amount], $priceColumns, $withPrice ? [
                ['prop' => 'avg_customer_amount', 'label' => '平均客单价', 'width' => 110, 'align' => 'right', 'type' => 'money'],
                ['prop' => 'return_amount', 'label' => '退货金额', 'width' => 110, 'align' => 'right', 'type' => 'money', 'danger' => true],
                ['prop' => 'net_amount', 'label' => '净销售金额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'bold' => true],
            ] : []);
        }

        $prefix = match ($dimension) {
            'salesman_customer' => [
                ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 160, 'align' => 'left', 'type' => 'text'],
            ],
            'salesman_product' => [
                ['prop' => 'product_code', 'label' => '商品编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'spec', 'label' => '规格', 'width' => 80, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'unit', 'label' => '单位', 'width' => 60, 'align' => 'center', 'type' => 'text'],
            ],
            'salesman_customer_product' => [
                ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 140, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'product_code', 'label' => '商品编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'product_name', 'label' => '商品名称', 'width' => 160, 'align' => 'left', 'type' => 'text'],
            ],
            default => [
                ['prop' => 'brand_name', 'label' => '品牌', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'product_code', 'label' => '商品编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'product_name', 'label' => '商品名称', 'width' => 160, 'align' => 'left', 'type' => 'text'],
            ],
        };

        return array_merge($salesman, $prefix, [$qty, $amount], $priceColumns);
    }
}
