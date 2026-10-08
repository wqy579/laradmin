<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 模块三：库存报表（报表管理 → 库存报表）
 *
 * 数据源是 stocks 快照表（quantity / frozen_qty / cost_price），不是实时流水累加：
 * 库存是状态量，用流水反推要回放全部出入库，成本也拿不到当前结存价。
 *
 * 「统计为0商品」默认关闭：库存为 0 的行在很多仓库里占绝大多数，
 * 默认带上会让有效数据被稀释到几十页之后。
 */
class StockReportController extends Controller
{
    private const DIMENSIONS = [
        'product_detail' => '商品明细',
        'product' => '商品汇总',
        'warehouse_product' => '仓库商品汇总',
        'brand' => '品牌汇总',
        'help' => '帮助视频',
    ];

    public function index(Request $request): JsonResponse
    {
        $params = ReportFilter::parse($request);
        $dimension = (string) ($params['dimension'] ?: 'product_detail');
        if (! array_key_exists($dimension, self::DIMENSIONS)) {
            $dimension = 'product_detail';
        }

        if ($dimension === 'help') {
            return $this->success([
                'dimension' => 'help', 'columns' => [], 'list' => [], 'total' => 0,
                'summary' => null, 'help' => $this->helpContent(),
            ]);
        }

        $paginator = $this->aggregate($params, $dimension)
            ->paginate($params['page_size'], ['*'], 'page', $params['page']);

        $data = ReportFilter::page($paginator);
        $data['dimension'] = $dimension;
        $data['columns'] = $this->columns($dimension);
        $data['summary'] = $this->summary($params, $dimension);

        return $this->success($data);
    }

    public function export(Request $request)
    {
        $params = ReportFilter::parse($request);
        $dimension = (string) ($params['dimension'] ?: 'product_detail');
        $columns = $this->columns($dimension);
        $rows = $this->aggregate($params, $dimension)->limit(50000)->get();

        $body = [];
        foreach ($rows as $row) {
            $body[] = array_map(fn ($c) => $row->{$c['prop']} ?? '', $columns);
        }

        return ReportFilter::csvResponse(
            ReportFilter::csv('库存报表 - '.(self::DIMENSIONS[$dimension] ?? ''), array_column($columns, 'label'), $body),
            'stock_report_'.$dimension.'.csv'
        );
    }

    private function baseQuery(array $params)
    {
        $query = DB::table('stocks as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('product_categories as mc', 'mc.id', '=', 'p.main_category_id')
            ->leftJoin('product_categories as sc', 'sc.id', '=', 'p.sub_category_id')
            ->where('p.is_active', 1);

        if ($params['warehouse_ids']) {
            $query->whereIn('s.warehouse_id', $params['warehouse_ids']);
        }
        if ($params['brand_ids']) {
            $query->whereIn('p.brand_id', $params['brand_ids']);
        }
        if ($params['main_category_ids']) {
            $query->whereIn('p.main_category_id', $params['main_category_ids']);
        }
        if ($params['sub_category_ids']) {
            $query->whereIn('p.sub_category_id', $params['sub_category_ids']);
        }
        if ($params['product_ids']) {
            $query->whereIn('p.id', $params['product_ids']);
        }
        if (! empty($params['product_keyword'])) {
            $kw = '%'.$params['product_keyword'].'%';
            $query->where(function ($q) use ($kw) {
                $q->where('p.name', 'like', $kw)->orWhere('p.code', 'like', $kw);
            });
        }

        // 统计为0商品：不勾就只看有库存的
        if (! ($params['include_zero'] ?? false)) {
            $query->where('s.quantity', '!=', 0);
        }

        return $query;
    }

    private function aggregate(array $params, string $dimension)
    {
        $query = $this->baseQuery($params);

        // 换算比：1 大单位 = unit_conversion 个小单位（varchar 存的是数字串，需显式转型，
        // 否则 MySQL/SQLite 会按字符串比较，除法结果不可预期）
        $conversion = 'CAST(COALESCE(NULLIF(p.unit_conversion, ""), "1") AS NUMERIC)';
        $costPrice = 'COALESCE(NULLIF(s.cost_price, 0), p.cost_price, 0)';

        $measures = [
            DB::raw('SUM(s.quantity) as qty'),
            DB::raw("SUM(s.quantity / {$conversion}) as qty_large"),
            DB::raw("{$costPrice} as cost_price"),
            DB::raw("SUM(s.quantity * {$costPrice}) as cost_amount"),
            DB::raw('COALESCE(p.price_small, p.price_large, 0) as retail_price'),
            DB::raw('SUM(s.quantity * COALESCE(p.price_small, p.price_large, 0)) as retail_amount'),
        ];

        switch ($dimension) {
            case 'product_detail':
                // 明细维度不聚合：一条 stocks 记录就是一行（仓库 × 商品的结存）
                return $query
                    ->select([
                        'w.code as warehouse_code', 'w.name as warehouse_name',
                        'p.code as product_code', 'p.name as product_name',
                        'p.spec', 'p.price_unit_small as unit', 'p.price_unit as unit_large',
                        DB::raw("{$conversion} as conversion_rate"),
                        'mc.name as main_category_name', 'sc.name as sub_category_name',
                        's.quantity as qty',
                        DB::raw("s.quantity / {$conversion} as qty_large"),
                        DB::raw("{$costPrice} as cost_price"),
                        DB::raw("s.quantity * {$costPrice} as cost_amount"),
                        DB::raw('COALESCE(p.price_small, p.price_large, 0) as retail_price'),
                        DB::raw('s.quantity * COALESCE(p.price_small, p.price_large, 0) as retail_amount'),
                    ])
                    ->orderBy('p.code');

            case 'product':
                return $query
                    ->select(array_merge($measures, [
                        'p.code as product_code', 'p.name as product_name', 'p.spec',
                        'p.price_unit_small as unit', 'p.price_unit as unit_large',
                        DB::raw("{$conversion} as conversion_rate"),
                        'mc.name as main_category_name', 'sc.name as sub_category_name',
                        DB::raw('COALESCE(b.name, "未指定品牌") as brand_name'),
                    ]))
                    ->groupBy('p.id', 'p.code', 'p.name', 'p.spec', 'p.price_unit_small', 'p.price_unit', 'mc.name', 'sc.name', 'b.name', 'p.cost_price', 'p.price_small', 'p.price_large', 'p.unit_conversion')
                    ->orderBy('p.code');

            case 'warehouse_product':
                return $query
                    ->select(array_merge($measures, [
                        'w.code as warehouse_code', 'w.name as warehouse_name',
                        'p.code as product_code', 'p.name as product_name', 'p.spec',
                        'p.price_unit_small as unit',
                    ]))
                    ->groupBy('w.id', 'w.code', 'w.name', 'p.id', 'p.code', 'p.name', 'p.spec', 'p.price_unit_small', 'p.cost_price', 'p.price_small', 'p.price_large', 'p.unit_conversion')
                    ->orderBy('w.code')->orderBy('p.code');

            case 'brand':
                return $query
                    ->select(array_merge([
                        DB::raw('SUM(s.quantity) as qty'),
                        DB::raw("SUM(s.quantity / {$conversion}) as qty_large"),
                        DB::raw("SUM(s.quantity * {$costPrice}) as cost_amount"),
                        DB::raw('SUM(s.quantity * COALESCE(p.price_small, p.price_large, 0)) as retail_amount'),
                    ], [
                        DB::raw('COALESCE(b.name, "未指定品牌") as brand_name'),
                        DB::raw('COUNT(DISTINCT p.id) as product_count'),
                    ]))
                    ->groupBy('b.id', 'b.name')
                    ->orderByDesc('cost_amount');
        }

        return $query;
    }

    private function summary(array $params, string $dimension): array
    {
        if ($dimension === 'product_detail') {
            $row = $this->baseQuery($params)
                ->select([
                    DB::raw('SUM(s.quantity) as qty'),
                    DB::raw('SUM(s.quantity * COALESCE(NULLIF(s.cost_price, 0), p.cost_price, 0)) as cost_amount'),
                    DB::raw('SUM(s.quantity * COALESCE(p.price_small, p.price_large, 0)) as retail_amount'),
                    DB::raw('COUNT(DISTINCT p.id) as product_count'),
                ])->first();
        } else {
            $rows = $this->aggregate($params, $dimension)->get();
            $row = (object) [
                'qty' => $rows->sum('qty'),
                'cost_amount' => $rows->sum('cost_amount'),
                'retail_amount' => $rows->sum('retail_amount'),
                'product_count' => $rows->count(),
            ];
        }

        return [
            'qty' => ReportFilter::num($row->qty ?? 0),
            'cost_amount' => ReportFilter::num($row->cost_amount ?? 0),
            'retail_amount' => ReportFilter::num($row->retail_amount ?? 0),
            'product_count' => (int) ($row->product_count ?? 0),
        ];
    }

    private function columns(string $dimension): array
    {
        $qty = ['prop' => 'qty', 'label' => '库存数量', 'width' => 100, 'align' => 'right', 'type' => 'number', 'zero_gray' => true, 'negative_red' => true];
        $qtyLarge = ['prop' => 'qty_large', 'label' => '大单位数量', 'width' => 100, 'align' => 'right', 'type' => 'number'];
        $costPrice = ['prop' => 'cost_price', 'label' => '成本单价', 'width' => 100, 'align' => 'right', 'type' => 'money'];
        $costAmount = ['prop' => 'cost_amount', 'label' => '成本金额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'medium' => true];
        $retailPrice = ['prop' => 'retail_price', 'label' => '零售单价', 'width' => 100, 'align' => 'right', 'type' => 'money'];
        $retailAmount = ['prop' => 'retail_amount', 'label' => '零售金额', 'width' => 120, 'align' => 'right', 'type' => 'money'];

        $product = [
            ['prop' => 'product_code', 'label' => '商品编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
            ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
            ['prop' => 'spec', 'label' => '规格', 'width' => 80, 'align' => 'left', 'type' => 'text'],
        ];

        switch ($dimension) {
            case 'product_detail':
                return array_merge([
                    ['prop' => 'warehouse_code', 'label' => '仓库编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'warehouse_name', 'label' => '仓库名称', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                ], $product, [
                    ['prop' => 'unit', 'label' => '单位', 'width' => 50, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'unit_large', 'label' => '大单位', 'width' => 70, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'conversion_rate', 'label' => '换算比', 'width' => 70, 'align' => 'right', 'type' => 'number'],
                    $qty, $qtyLarge, $costPrice, $costAmount, $retailPrice, $retailAmount,
                ]);

            case 'product':
                return array_merge($product, [
                    ['prop' => 'unit', 'label' => '单位', 'width' => 50, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'unit_large', 'label' => '大单位', 'width' => 70, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'conversion_rate', 'label' => '换算比', 'width' => 70, 'align' => 'right', 'type' => 'number'],
                    ['prop' => 'main_category_name', 'label' => '商品大类', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'sub_category_name', 'label' => '商品小类', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'brand_name', 'label' => '品牌', 'width' => 90, 'align' => 'left', 'type' => 'text'],
                    $qty, $qtyLarge, $costPrice, $costAmount, $retailPrice, $retailAmount,
                ]);

            case 'warehouse_product':
                return array_merge([
                    ['prop' => 'warehouse_code', 'label' => '仓库编码', 'width' => 100, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'warehouse_name', 'label' => '仓库名称', 'width' => 120, 'align' => 'left', 'type' => 'text'],
                ], $product, [
                    ['prop' => 'unit', 'label' => '单位', 'width' => 50, 'align' => 'center', 'type' => 'text'],
                    $qty, $qtyLarge, $costPrice, $costAmount, $retailPrice, $retailAmount,
                ]);

            case 'brand':
                return [
                    ['prop' => 'brand_name', 'label' => '品牌', 'width' => 160, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_count', 'label' => '商品数', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                    $qty, $qtyLarge, $costAmount, $retailAmount,
                ];
        }

        return [];
    }

    private function helpContent(): array
    {
        return [
            'title' => '库存报表使用说明',
            'steps' => [
                '库存取自 stocks 结存快照，不是出入库流水累加，因此与「库存流水」对不上时以本表为准。',
                '大单位数量 = 库存数量 ÷ 换算比，换算比来自商品档案（1 大单位 = N 小单位）。',
                '库存为负说明有超卖或未审核单据先减了库存，负数行标红，需要尽快核对。',
                '「统计为0商品」勾选后会带出零库存商品，用于盘查有档案无库存的死档。',
            ],
            'video_url' => null,
        ];
    }
}
