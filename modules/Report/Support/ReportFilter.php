<?php

namespace Modules\Report\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 报表筛选条件的统一解析与装配。
 *
 * 各报表页面（销售 / 业务员 / 库存 / 最近价格）共用一套筛选语义，
 * 这里只做「参数 → 查询条件」的翻译，不碰具体维度聚合，避免五个控制器各写一遍。
 *
 * 关于日期类型（连凯方案里的 5 种）：本库没有为每种日期各存一列，
 * 映射如下，映射关系同时回传给前端（ReportOptionController::dateTypes），
 * 下拉里显示的就是真实生效的字段，不藏着转换：
 *
 *   payment   付款日期 → receives.receive_date（客户付款/收款单日期）
 *   declare   申报日期 → sales_orders.order_date（下单即申报）
 *   dispatch  配货日期 → sales_orders.dispatch_date
 *   reconcile 对账日期 → sales_orders.reconcile_date
 *   returned  回款日期 → cash_flows.flow_date（资金回款流水，type=receive）
 *
 * 付款/回款都不在 sales_orders 上，用 whereExists 过滤而不是 join：
 * 一个订单可能有多笔收款，join 会把订单行放大成多行，金额汇总直接翻倍。
 */
class ReportFilter
{
    /** 日期类型 → 销售订单表上的日期列（列在订单表内的直接 whereBetween） */
    private const ORDER_DATE_COLUMNS = [
        'declare' => 'so.order_date',
        'dispatch' => 'so.dispatch_date',
        'reconcile' => 'so.reconcile_date',
    ];

    /**
     * 解析请求里的公共筛选项
     *
     * @return array<string, mixed>
     */
    public static function parse(Request $request): array
    {
        $int = fn (string $key) => $request->filled($key) ? (int) $request->input($key) : null;

        return [
            // 日期
            'date_type' => $request->input('date_type', 'declare'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'start_time' => $request->input('start_time'),
            'end_time' => $request->input('end_time'),
            // 客户维度
            'customer_category' => self::toArray($request->input('customer_category')),
            'customer_level_ids' => self::toArray($request->input('customer_level_ids')),
            'channel_category' => self::toArray($request->input('channel_category')),
            'channel_sub_category' => self::toArray($request->input('channel_sub_category')),
            'customer_ids' => self::toArray($request->input('customer_ids')),
            // 商品维度
            'brand_ids' => self::toArray($request->input('brand_ids')),
            'main_category_ids' => self::toArray($request->input('main_category_ids')),
            'sub_category_ids' => self::toArray($request->input('sub_category_ids')),
            'product_ids' => self::toArray($request->input('product_ids')),
            'warehouse_ids' => self::toArray($request->input('warehouse_ids')),
            'product_keyword' => $request->input('product_keyword'),
            'product_code' => $request->input('product_code'),
            'product_name' => $request->input('product_name'),
            'customer_code' => $request->input('customer_code'),
            'customer_name' => $request->input('customer_name'),
            'is_new' => $request->boolean('is_new'),
            'is_key' => $request->boolean('is_key'),
            // 单据维度
            'sale_types' => self::toArray($request->input('sale_types')),
            'doc_types' => self::toArray($request->input('doc_types')),
            'payment_methods' => self::toArray($request->input('payment_methods')),
            'sources' => self::toArray($request->input('sources')),
            'salesman_ids' => self::toArray($request->input('salesman_ids')),
            'delivery_person_ids' => self::toArray($request->input('delivery_person_ids')),
            'operator_ids' => self::toArray($request->input('operator_ids')),
            'approver_ids' => self::toArray($request->input('approver_ids')),
            'order_no' => $request->input('order_no'),
            'delivery_no' => $request->input('delivery_no'),
            'remark' => $request->input('remark'),
            'include_red_flush' => $request->boolean('include_red_flush'),
            // 库存报表：统计为 0 的商品（默认不带出，零库存行会淹没有效数据）
            'include_zero' => $request->boolean('include_zero'),
            // 展示控制
            'with_price' => $request->boolean('with_price', true),
            'zero_sale' => $request->input('zero_sale'),
            // 分页
            'page' => max(1, (int) $request->input('page', 1)),
            'page_size' => min(500, max(1, (int) $request->input('page_size', 30))),
            'dimension' => $request->input('dimension'),
            '__int' => $int,
        ];
    }

    /**
     * 把日期区间套到查询上
     *
     * 日期列是 date 类型时，时间选择器（开始时间/结束时间）不参与——
     * 那是给 datetime 列（如 approved_at）准备的，硬拼上去会变成 00:00:00~23:59:59
     * 之外的无效字符串。付款/回款两种类型走 whereExists 子查询。
     */
    public static function applyDateRange($query, array $params): void
    {
        $type = $params['date_type'] ?? 'declare';
        $start = $params['start_date'] ?? null;
        $end = $params['end_date'] ?? null;

        if (! $start && ! $end) {
            return;
        }

        $start = $start ?: '1970-01-01';
        $end = $end ?: '2999-12-31';

        if ($type === 'payment') {
            $query->whereExists(function ($sub) use ($start, $end) {
                $sub->from('receives')
                    ->whereColumn('receives.sales_order_id', 'so.id')
                    ->whereBetween('receives.receive_date', [$start, $end]);
            });

            return;
        }

        if ($type === 'returned') {
            $query->whereExists(function ($sub) use ($start, $end) {
                $sub->from('cash_flows')
                    ->whereColumn('cash_flows.related_id', 'so.id')
                    ->where('cash_flows.related_type', 'sales_order')
                    ->where('cash_flows.flow_type', 'receive')
                    ->whereBetween('cash_flows.flow_date', [$start, $end]);
            });

            return;
        }

        $column = self::ORDER_DATE_COLUMNS[$type] ?? self::ORDER_DATE_COLUMNS['declare'];
        $query->whereBetween($column, [$start, $end]);
    }

    /**
     * 客户 / 商品 / 仓库 / 品牌 / 分类维度筛选
     *
     * 别名固定：so = sales_orders，soi = sales_order_items，p = products，c = customers。
     * 调用方必须按这套别名 join，否则列名解析不到。
     */
    public static function applyDimensions($query, array $params): void
    {
        if ($params['customer_category']) {
            $query->whereIn('c.category', $params['customer_category']);
        }
        if ($params['customer_level_ids']) {
            $query->whereIn('c.level_id', $params['customer_level_ids']);
        }
        if ($params['channel_category']) {
            $query->whereIn('c.channel_category', $params['channel_category']);
        }
        if ($params['channel_sub_category']) {
            $query->whereIn('c.channel_sub_category', $params['channel_sub_category']);
        }
        if ($params['customer_ids']) {
            $query->whereIn('so.customer_id', $params['customer_ids']);
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
            $query->whereIn('soi.product_id', $params['product_ids']);
        }
        if ($params['warehouse_ids']) {
            $query->whereIn('so.warehouse_id', $params['warehouse_ids']);
        }
        if (! empty($params['product_keyword'])) {
            $kw = '%'.$params['product_keyword'].'%';
            $query->where(function ($q) use ($kw) {
                $q->where('p.name', 'like', $kw)->orWhere('p.code', 'like', $kw);
            });
        }
        if (! empty($params['product_name'])) {
            $query->where('p.name', 'like', '%'.$params['product_name'].'%');
        }
        if (! empty($params['customer_name'])) {
            $query->where('c.name', 'like', '%'.$params['customer_name'].'%');
        }
        if ($params['is_new']) {
            $query->where('p.is_new', 1);
        }
        if ($params['is_key']) {
            $query->where('p.is_key', 1);
        }
    }

    /** 单据维度筛选（销售类型 / 单据类型 / 收款方式 / 来源 / 人员 / 单号 / 备注 / 红冲） */
    public static function applyOrderFilters($query, array $params): void
    {
        if ($params['sale_types']) {
            $query->whereIn('so.order_type', $params['sale_types']);
        }
        if ($params['sources']) {
            $query->whereIn('so.source', $params['sources']);
        }
        if ($params['salesman_ids']) {
            $query->whereIn('so.salesman_id', $params['salesman_ids']);
        }
        if ($params['delivery_person_ids']) {
            $query->whereIn('so.delivery_person_id', $params['delivery_person_ids']);
        }
        if ($params['operator_ids']) {
            $query->whereIn('so.created_by', $params['operator_ids']);
        }
        if ($params['approver_ids']) {
            $query->whereIn('so.approved_by', $params['approver_ids']);
        }
        if (! empty($params['order_no'])) {
            $query->where('so.order_no', 'like', '%'.$params['order_no'].'%');
        }
        if (! empty($params['remark'])) {
            $query->where('so.remark', 'like', '%'.$params['remark'].'%');
        }

        // 红冲单：original_order_id 非空即红冲产生的负单，默认剔除，勾「包含红冲」才并入
        if (! $params['include_red_flush']) {
            $query->whereNull('so.original_order_id');
        }

        // 收款方式 / 出库单号：都不在订单表上，走 exists 子查询避免行放大
        if ($params['payment_methods']) {
            $methods = $params['payment_methods'];
            $query->whereExists(function ($sub) use ($methods) {
                $sub->from('receives')
                    ->whereColumn('receives.sales_order_id', 'so.id')
                    ->whereIn('receives.payment_method', $methods);
            });
        }

        if (! empty($params['delivery_no'])) {
            $no = '%'.$params['delivery_no'].'%';
            $query->whereExists(function ($sub) use ($no) {
                $sub->from('deliveries')
                    ->whereColumn('deliveries.order_id', 'so.id')
                    ->where('deliveries.delivery_no', 'like', $no);
            });
        }
    }

    /**
     * 统一把前端的单个值 / 逗号串 / 数组都收敛成整型数组
     *
     * 前端多选下拉在「未选」时可能传 ''、null、[]，在「单选」时传标量，
     * 这里一次性兜住，下游只面对数组。
     */
    public static function toArray(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_array($value)) {
            $value = array_filter($value, fn ($v) => $v !== null && $v !== '');
        } else {
            $value = explode(',', (string) $value);
        }

        return array_values(array_map('intval', $value));
    }

    /** 分页器转前端统一结构（list + total + page + page_size） */
    public static function page($paginator): array
    {
        return [
            'list' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    /**
     * 金额/数量统一保留 2 位小数并转浮点（前端直接 toFixed(2)）
     *
     * DB 聚合出来的是 string（decimal）或 null，不处理会让前端出现 "0.00"→"" 之外的
     * 各种怪值；统一在这里收敛。
     */
    public static function num(mixed $value): float
    {
        return round((float) $value, 2);
    }

    /** CSV 导出：拼 BOM + 表头 + 行，返回可直接响应的字符串 */
    public static function csv(string $title, array $headers, array $rows): string
    {
        $csv = "\u{FEFF}".$title."\n\n";
        $csv .= implode(',', $headers)."\n";

        foreach ($rows as $row) {
            $csv .= implode(',', array_map(function ($cell) {
                $cell = (string) $cell;

                return str_contains($cell, ',') || str_contains($cell, '"') || str_contains($cell, "\n")
                    ? '"'.str_replace('"', '""', $cell).'"'
                    : $cell;
            }, $row))."\n";
        }

        return $csv;
    }

    /** CSV 下载响应（文件名统一 ASCII 前缀，避免各浏览器中文名差异） */
    public static function csvResponse(string $csv, string $filename)
    {
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** 取用户姓名快照表：报表里到处要「ID → 姓名」 */
    public static function userNames(): array
    {
        return DB::table('auth_user')->pluck('real_name', 'id')->all();
    }
}
