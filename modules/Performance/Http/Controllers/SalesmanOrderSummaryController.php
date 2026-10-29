<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 业务员订单汇总（报表 → 业务员订单汇总，任务 C7）。
 *
 * 口径：仅 status ∈ (approved, completed) 的销售订单计入有效销售，
 * draft/pending/cancelled 一律排除；三个页签共用一套筛选
 * （下单日期区间 + 业务员 + 客户）。
 * 金额服务端统一 round 2 位、空结果返回数值 0，界面不出现 NaN/浮点噪声；
 * 列表合计行恒等于本页返回行之和（所见即所合计），导出与界面数字一致。
 */
class SalesmanOrderSummaryController extends Controller
{
    /** 计入有效销售的单据状态 */
    private const EFFECTIVE_STATUSES = ['approved', 'completed'];

    /** 页签一：商品明细行（一订单一商品一行），分页 */
    public function items(Request $request): JsonResponse
    {
        [$page, $pageSize] = $this->pageParams($request);

        $query = $this->itemQuery($request);
        $total = $query->count();
        $list = $this->mapItems($query
            ->orderByDesc('so.order_date')->orderByDesc('so.id')->orderBy('i.id')
            ->forPage($page, $pageSize)
            ->get());

        return $this->success([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'totals' => [
                'qty' => (int) $list->sum('qty'),
                'amount' => ReportFilter::num($list->sum('amount')),
            ],
        ]);
    }

    /** 页签二：客户×商品汇总（业务员+客户+商品 分组），分页 */
    public function customerItem(Request $request): JsonResponse
    {
        [$page, $pageSize] = $this->pageParams($request);

        $rows = $this->customerRows($request);
        $list = $rows->forPage($page, $pageSize)->values();

        return $this->success([
            'list' => $list,
            'total' => $rows->count(),
            'page' => $page,
            'page_size' => $pageSize,
            'totals' => [
                'qty' => (int) $list->sum('qty'),
                'amount' => ReportFilter::num($list->sum('amount')),
            ],
        ]);
    }

    /** 页签三：业务员订单汇总（不分页：业务员数量有限，前端还要画排行图） */
    public function salesman(Request $request): JsonResponse
    {
        $list = $this->salesmanRows($request);
        $grand = $list->sum('order_amount');

        return $this->success([
            'list' => $list->values(),
            'total' => $list->count(),
            'totals' => [
                'order_count' => (int) $list->sum('order_count'),
                'goods_item_count' => (int) $list->sum('goods_item_count'),
                'order_amount' => ReportFilter::num($grand),
                'revenue' => $list->isEmpty() ? 0 : 100.00,
            ],
        ]);
    }

    /** 同筛选导出：三段 CSV（商品明细全量 + 客户商品汇总 + 业务员汇总），数字与界面一致 */
    public function export(Request $request)
    {
        $items = $this->mapItems($this->itemQuery($request)
            ->orderByDesc('so.order_date')->orderByDesc('so.id')->orderBy('i.id')
            ->get());
        $itemCsv = ReportFilter::csv('商品明细',
            ['日期', '单号', '业务员', '客户', '商品编码', '商品名称', '品牌', '数量', '单价', '金额', '状态'],
            $items->map(fn ($r) => [
                $r['apply_date'], $r['order_no'], $r['salesman'], $r['customer'],
                $r['product_code'], $r['product_name'], $r['brand'],
                $r['qty'], number_format($r['unit_price'], 2, '.', ''),
                number_format($r['amount'], 2, '.', ''), $r['status'],
            ])->all());

        $customerCsv = ReportFilter::csv('客户商品汇总',
            ['业务员', '客户', '商品名称', '数量', '金额', '订单数'],
            $this->customerRows($request)->map(fn ($r) => [
                $r['salesman'], $r['customer'], $r['product_name'],
                $r['qty'], number_format($r['amount'], 2, '.', ''), $r['order_count'],
            ])->all());

        $salesmanCsv = ReportFilter::csv('业务员订单汇总',
            ['业务员', '订单数', '商品行数', '订单金额', '占比%', '排名'],
            $this->salesmanRows($request)->map(fn ($r) => [
                $r['sales_name'], $r['order_count'], $r['goods_item_count'],
                number_format($r['order_amount'], 2, '.', ''),
                number_format($r['revenue'], 2, '.', ''), $r['rank'],
            ])->all());

        // 段与段各带一次 BOM，仅保留首段，Excel 打开才不吞第一个表头
        $csv = $itemCsv.preg_replace('/^\x{FEFF}/u', '', $customerCsv, 1)
            .preg_replace('/^\x{FEFF}/u', '', $salesmanCsv, 1);

        return ReportFilter::csvResponse($csv, 'salesman_order_summary_'.date('Ymd_His').'.csv');
    }

    /** 页签一/导出的明细行查询：显式列（六表 join，select * 会串列名） */
    private function itemQuery(Request $request)
    {
        $amt = $this->itemAmountExpr();

        return $this->base($request)->select([
            'so.order_date',
            'so.order_no',
            'so.status',
            DB::raw('COALESCE(sm.real_name, sm.username, so.salesman_name) as salesman'),
            'c.name as customer',
            'p.code as product_code',
            'p.name as product_name',
            'b.name as brand',
            'i.quantity',
            'i.price',
            DB::raw("{$amt} as amount"),
        ]);
    }

    /** 订单×明细 join + 有效状态 + 筛选（各页签共用起点） */
    private function base(Request $request)
    {
        return $this->applyFilters(
            DB::table('sales_orders as so')
                ->join('sales_order_items as i', 'i.sales_order_id', '=', 'so.id')
                ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
                ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
                ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
                ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id'),
            $request
        );
    }

    /** 有效销售 + 日期区间 + 业务员 + 客户（订单表/订单×明细表两种起点共用） */
    private function applyFilters($query, Request $request)
    {
        $start = trim((string) $request->input('apply_date_start', ''));
        $end = trim((string) $request->input('apply_date_end', ''));
        $salesmanId = trim((string) $request->input('salesman_id', ''));
        $customerId = trim((string) $request->input('customer_id', ''));

        return $query
            ->whereIn('so.status', self::EFFECTIVE_STATUSES)
            ->when($start !== '', fn ($q) => $q->whereDate('so.order_date', '>=', $start))
            ->when($end !== '', fn ($q) => $q->whereDate('so.order_date', '<=', $end))
            ->when($salesmanId !== '', fn ($q) => $q->where('so.salesman_id', (int) $salesmanId))
            ->when($customerId !== '', fn ($q) => $q->where('so.customer_id', (int) $customerId));
    }

    /** 明细查询行 → 前端/CSV 行（金额 2 位、数量取整） */
    private function mapItems($rows)
    {
        return $rows->map(fn ($o) => [
            'apply_date' => substr((string) $o->order_date, 0, 10),
            'order_no' => $o->order_no,
            'salesman' => $o->salesman ?: '-',
            'customer' => $o->customer ?: '-',
            'product_code' => $o->product_code,
            'product_name' => $o->product_name,
            'brand' => $o->brand ?? '',
            'qty' => (int) $o->quantity,
            'unit_price' => ReportFilter::num($o->price),
            'amount' => ReportFilter::num($o->amount),
            'status' => $o->status,
        ])->values();
    }

    /** 页签二全量分组行：业务员+客户+商品 → 数量/金额/订单数（订单数按 DISTINCT 单） */
    private function customerRows(Request $request)
    {
        $amt = $this->itemAmountExpr();

        return $this->base($request)
            ->groupBy(['sm.real_name', 'sm.username', 'so.salesman_name', 'c.id', 'c.name', 'i.product_id', 'p.name'])
            ->selectRaw("
                COALESCE(sm.real_name, sm.username, so.salesman_name) as salesman,
                c.name as customer,
                p.name as product_name,
                SUM(i.quantity) as qty,
                SUM({$amt}) as amount,
                COUNT(DISTINCT so.id) as order_count
            ")
            ->orderByDesc('amount')
            ->orderBy('salesman')
            ->orderBy('product_name')
            ->get()
            ->map(fn ($o) => [
                'salesman' => $o->salesman ?: '-',
                'customer' => $o->customer ?: '-',
                'product_name' => $o->product_name,
                'qty' => (int) $o->qty,
                'amount' => ReportFilter::num($o->amount),
                'order_count' => (int) $o->order_count,
            ]);
    }

    /**
     * 页签三：业务员 → 订单数/商品行数/订单金额/占比/排名。
     *
     * 订单金额是订单级 total_amount 之和：join 明细会把订单金额按行数放大，
     * 故订单级数字单独在订单表聚合（仅统计有明细的订单），明细行数再按业务员合并。
     */
    private function salesmanRows(Request $request)
    {
        $orders = $this->applyFilters(
            DB::table('sales_orders as so')
                ->leftJoin('auth_user as sm', 'sm.id', '=', 'so.salesman_id')
                ->whereExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('sales_order_items as i')
                    ->whereColumn('i.sales_order_id', 'so.id')),
            $request
        )
            ->groupBy(['sm.real_name', 'sm.username', 'so.salesman_name'])
            ->selectRaw('
                COALESCE(sm.real_name, sm.username, so.salesman_name) as salesman,
                COUNT(*) as order_count,
                SUM(so.total_amount) as order_amount
            ')
            ->get()
            ->keyBy(fn ($o) => $o->salesman ?: '-');

        $items = $this->base($request)
            ->groupBy(['sm.real_name', 'sm.username', 'so.salesman_name'])
            ->selectRaw('
                COALESCE(sm.real_name, sm.username, so.salesman_name) as salesman,
                COUNT(i.id) as goods_item_count
            ')
            ->get();

        $grand = $orders->sum('order_amount');

        return $items
            ->map(function ($o) use ($orders) {
                $key = $o->salesman ?: '-';
                $agg = $orders->get($key);

                return [
                    'sales_name' => $key,
                    'order_count' => (int) ($agg->order_count ?? 0),
                    'goods_item_count' => (int) $o->goods_item_count,
                    'order_amount' => ReportFilter::num($agg->order_amount ?? 0),
                ];
            })
            ->sortByDesc('order_amount')
            ->values()
            ->map(function (array $r, int $i) use ($grand) {
                $r['revenue'] = $grand > 0 ? round($r['order_amount'] / $grand * 100, 2) : 0;
                $r['rank'] = $i + 1;

                return $r;
            });
    }

    /** 明细行金额表达式：amount 为权威列，为 0/空时回退 数量×单价 */
    private function itemAmountExpr(): string
    {
        return 'COALESCE(NULLIF(i.amount, 0), i.quantity * i.price)';
    }

    /** 分页参数：默认 1/20，page_size 上限 200 */
    private function pageParams(Request $request): array
    {
        return [
            max(1, (int) $request->input('page', 1)),
            min(200, max(1, (int) $request->input('page_size', 20))),
        ];
    }
}
