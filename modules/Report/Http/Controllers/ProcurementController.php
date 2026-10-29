<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Report\Support\ReportFilter;

/**
 * 模块六：采购+入出库+费用+价格报表（报表管理 → 采购/费用）
 *
 * 8 个维度共用一套筛选条件集，切 tab 只换 dimension：
 *   purchase 采购汇总 / stock_in 入库明细 / transfer 调拨明细 /
 *   scrap 报废报损 / stock_in_summary 入库汇总 / stock_detail_summary 收发存汇总 /
 *   expense 费用查询 / price_plan 价格方案。
 *
 * 口径（执行文档 §3 E6）：
 *   采购汇总   按供应商汇总：采购额 = 已审核/已转库采购申请单金额合计，
 *             退货额 = 已审核采购退货单金额合计，净额 = 采购额 − 退货额。
 *   入库明细    stock_ins × stock_in_items 逐行（含类型：purchase/adjustment）。
 *   调拨明细    transfers × transfer_items 逐行（status in approved/completed）。
 *   报废报损    库存调整单 adjust_type=stock_loss（已审核）逐行，正数=损耗出库。
 *   入库汇总    stock_in_items 按商品汇总数量/金额。
 *   收发存汇总  期初 + 收入 − 发出 = 期末，流水以 stocks_history 为准（与 C 机同源），
 *             期末 = stocks.quantity 当前结存，期初 = 期末 − 期间净变动。
 *   费用查询    expenses 逐单（费用类型多选），合计行 = 明细之和（验收口径）。
 *   价格方案    商品 × 客户等级价格宽表（product_level_prices + 标准售价），
 *             区别于 E4 价格矩阵（那是面向客户的成交价口径，这是方案/挂牌价）。
 *
 * 纯读模块：只读采购/入库/调拨/调整/费用/价格方案等既有表，不写任何业务表。
 */
class ProcurementController extends Controller
{
    /** 维度 key → 中文名（顺序即 tab 顺序） */
    private const DIMENSIONS = [
        'purchase' => '采购汇总',
        'stock_in' => '入库明细',
        'transfer' => '调拨明细',
        'scrap' => '报废报损',
        'stock_in_summary' => '入库汇总',
        'stock_detail_summary' => '收发存汇总',
        'expense' => '费用查询',
        'price_plan' => '价格方案',
    ];

    /** 采购申请单计入采购额的状态：已审核(approved) 与 已转库(transferred) */
    private const PURCHASE_STATUS = ['approved', 'transferred'];

    public function index(Request $request): JsonResponse
    {
        $params = ReportFilter::parse($request);
        $dimension = $this->dimension($params);

        $summary = [];
        $rows = collect();

        if ($dimension === 'purchase') {
            // 采购额/退货额分属两张表，PHP 侧按供应商合并后算净额 → 手工分页
            $rows = collect($this->purchaseMergedRows($params));
        } elseif ($dimension === 'stock_detail_summary') {
            // 收发存：期初/期末需结合 stocks_history 净变动与当前结存，PHP 侧算 → 手工分页
            $rows = collect($this->stockDetailQuery($params));
        } elseif ($dimension === 'price_plan') {
            // 价格方案列是动态的（按启用等级生成），等级价在 PHP 侧回填 → 手工分页
            $rows = collect($this->enrichPricePlan($this->pricePlanQuery($params)->get()));
        } else {
            // DB 直分页维度：先分页，合计对全量行单独跑
            $query = $this->aggregate($params, $dimension);
            $paginator = $query->paginate($params['page_size'], ['*'], 'page', $params['page']);
            $data = ReportFilter::page($paginator);
            $data['dimension'] = $dimension;
            $data['dimensions'] = self::DIMENSIONS;
            $data['columns'] = $this->columns($dimension);
            $data['summary'] = $this->summary($query->get(), $dimension);

            return $this->success($data);
        }

        $data = ReportFilter::page($this->paginateRows($rows, $request, $params));
        $data['dimension'] = $dimension;
        $data['dimensions'] = self::DIMENSIONS;
        $data['columns'] = $this->columns($dimension);
        $data['summary'] = $this->summary($rows, $dimension);

        return $this->success($data);
    }

    public function export(Request $request)
    {
        $params = ReportFilter::parse($request);
        $dimension = $this->dimension($params);

        if ($dimension === 'purchase') {
            $rows = $this->purchaseMergedRows($params);
        } elseif ($dimension === 'stock_detail_summary') {
            $rows = $this->stockDetailQuery($params);
        } elseif ($dimension === 'price_plan') {
            $rows = $this->enrichPricePlan($this->pricePlanQuery($params)->limit(50000)->get());
        } else {
            $rows = $this->aggregate($params, $dimension)->limit(50000)->get()->all();
        }

        return ReportFilter::excelResponse(
            ReportFilter::excel('采购费用 - '.(self::DIMENSIONS[$dimension] ?? ''), $this->columns($dimension), $rows),
            'procurement_'.$dimension.'.xls'
        );
    }

    private function dimension(array $params): string
    {
        $dimension = (string) ($params['dimension'] ?? 'purchase');

        return array_key_exists($dimension, self::DIMENSIONS) ? $dimension : 'purchase';
    }

    // ================================================================
    // 穿透（可穿）：双击行 → 源单/源明细
    // 8 个维度共用一口径：明细类维度回源单表头+行，聚合类维度回该实体下的明细行。
    // 载荷约定：doc 型维度传 order_no / adjust_no / expense_no；聚合型传 supplier_id / product_id。
    // ================================================================

    public function drill(Request $request): JsonResponse
    {
        $dimension = $this->dimension($request->all());

        return match ($dimension) {
            'purchase' => $this->drillPurchase($request),
            'stock_in' => $this->drillStockIn($request),
            'transfer' => $this->drillTransfer($request),
            'scrap' => $this->drillScrap($request),
            'stock_in_summary' => $this->drillStockInSummary($request),
            'stock_detail_summary' => $this->drillStockDetail($request),
            'expense' => $this->drillExpense($request),
            'price_plan' => $this->drillPricePlan($request),
            default => $this->error('未知维度', 422),
        };
    }

    /** 采购汇总穿透：该供应商区间内的采购申请单 + 退货单 */
    private function drillPurchase(Request $request): JsonResponse
    {
        $validated = $request->validate(['supplier_id' => 'required|integer']);

        $supplier = DB::table('suppliers')->where('id', $validated['supplier_id'])->first(['id', 'code', 'name']);
        if (! $supplier) {
            return $this->error('供应商不存在', 404);
        }

        $params = ReportFilter::parse($request);
        $header = $this->drillHeader('供应商采购明细', $supplier->code, [
            ['label' => '供应商', 'value' => $supplier->name],
            ['label' => '起止日期', 'value' => ($params['start_date'] ?: '最早').' ~ '.($params['end_date'] ?: '最新')],
        ]);

        $apps = DB::table('purchase_applications as pa')
            ->where('pa.supplier_id', $validated['supplier_id'])
            ->whereIn('pa.status', self::PURCHASE_STATUS);
        $this->applyDateRange($apps, $params, 'pa.apply_date');
        $apps = $apps->get(['pa.apply_no', 'pa.apply_date', 'pa.status', 'pa.total_quantity', 'pa.total_amount'])
            ->map(fn ($r) => (object) [
                'biz_type' => '采购申请',
                'doc_no' => $r->apply_no,
                'doc_date' => $r->apply_date,
                'status' => $this->purchaseStatusLabel($r->status),
                'qty' => ReportFilter::num($r->total_quantity),
                'amount' => ReportFilter::num($r->total_amount),
            ]);

        $returns = DB::table('purchase_returns as pr')
            ->where('pr.supplier_id', $validated['supplier_id'])
            ->where('pr.status', 'approved');
        $this->applyDateRange($returns, $params, 'pr.return_date');
        $returns = $returns->get(['pr.return_no', 'pr.return_date', 'pr.total_qty', 'pr.total_amount'])
            ->map(fn ($r) => (object) [
                'biz_type' => '采购退货',
                'doc_no' => $r->return_no,
                'doc_date' => $r->return_date,
                'status' => '已审核',
                'qty' => ReportFilter::num($r->total_qty),
                'amount' => ReportFilter::num($r->total_amount),
            ]);

        $items = $apps->concat($returns)->sortByDesc('doc_date')->values()->all();

        return $this->success([
            'header' => $header,
            'items' => $items,
            'columns' => [
                ['prop' => 'biz_type', 'label' => '类型', 'width' => 90, 'align' => 'center', 'type' => 'text'],
                ['prop' => 'doc_no', 'label' => '单据号', 'width' => 150, 'align' => 'left', 'type' => 'text', 'bold' => true],
                ['prop' => 'doc_date', 'label' => '日期', 'width' => 100, 'align' => 'center', 'type' => 'text'],
                ['prop' => 'status', 'label' => '状态', 'width' => 90, 'align' => 'center', 'type' => 'text'],
                ['prop' => 'qty', 'label' => '数量', 'width' => 90, 'align' => 'right', 'type' => 'number'],
                ['prop' => 'amount', 'label' => '金额', 'width' => 110, 'align' => 'right', 'type' => 'money', 'bold' => true],
            ],
        ]);
    }

    private function purchaseStatusLabel(string $status): string
    {
        return match ($status) {
            'approved' => '已审核',
            'transferred' => '已转库',
            'draft' => '草稿',
            default => $status,
        };
    }

    /** 入库明细穿透：入库单表头 + 行明细 */
    private function drillStockIn(Request $request): JsonResponse
    {
        $validated = $request->validate(['order_no' => 'required|string|max:50']);

        $header = DB::table('stock_ins as sih')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sih.warehouse_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'sih.supplier_id')
            ->where('sih.order_no', $validated['order_no'])
            ->selectRaw("
                sih.order_no, sih.stock_date,
                case sih.type when 'purchase' then '采购入库' when 'adjustment' then '调整入库' else sih.type end as type_label,
                COALESCE(w.name, '') as warehouse_name,
                COALESCE(s.name, '') as supplier_name,
                COALESCE(sih.total_amount, 0) as total_amount,
                COALESCE(sih.remark, '') as remark
            ")
            ->first();

        if (! $header) {
            return $this->error('入库单不存在', 404);
        }

        $items = DB::table('stock_in_items as si')
            ->leftJoin('products as p', 'p.id', '=', 'si.product_id')
            ->where('si.stock_in_id', DB::table('stock_ins')->where('order_no', $validated['order_no'])->value('id'))
            ->selectRaw("
                COALESCE(p.code, si.product_id) as product_code,
                COALESCE(p.name, CONCAT('商品#', si.product_id)) as product_name,
                COALESCE(p.spec, '') as spec, COALESCE(p.price_unit_small, '') as unit,
                si.quantity, si.price, si.amount
            ")
            ->orderByDesc('si.amount')
            ->get();

        return $this->success([
            'header' => $this->drillHeader($header->order_no, '', [
                ['label' => '入库类型', 'value' => $header->type_label],
                ['label' => '仓库', 'value' => $header->warehouse_name],
                ['label' => '供应商', 'value' => $header->supplier_name],
                ['label' => '入库日期', 'value' => $header->stock_date],
                ['label' => '备注', 'value' => $header->remark],
            ], [['label' => '入库金额', 'value' => ReportFilter::num($header->total_amount)]]),
            'items' => $items,
            'columns' => $this->drillProductColumns(),
        ]);
    }

    /** 调拨明细穿透：调拨单表头 + 行明细 */
    private function drillTransfer(Request $request): JsonResponse
    {
        $validated = $request->validate(['order_no' => 'required|string|max:50']);

        $header = DB::table('transfers as t')
            ->leftJoin('warehouses as fw', 'fw.id', '=', 't.from_warehouse_id')
            ->leftJoin('warehouses as tw', 'tw.id', '=', 't.to_warehouse_id')
            ->where('t.order_no', $validated['order_no'])
            ->selectRaw("
                t.order_no, t.transfer_date,
                COALESCE(fw.name, '') as from_warehouse, COALESCE(tw.name, '') as to_warehouse,
                COALESCE(t.total_amount, 0) as total_amount, COALESCE(t.remark, '') as remark
            ")
            ->first();

        if (! $header) {
            return $this->error('调拨单不存在', 404);
        }

        $items = DB::table('transfer_items as ti')
            ->leftJoin('products as p', 'p.id', '=', 'ti.product_id')
            ->where('ti.transfer_id', DB::table('transfers')->where('order_no', $validated['order_no'])->value('id'))
            ->selectRaw("
                COALESCE(p.code, ti.product_id) as product_code,
                COALESCE(p.name, CONCAT('商品#', ti.product_id)) as product_name,
                COALESCE(p.spec, '') as spec, COALESCE(p.price_unit_small, '') as unit,
                ti.quantity, ti.price, ti.amount
            ")
            ->orderByDesc('ti.amount')
            ->get();

        return $this->success([
            'header' => $this->drillHeader($header->order_no, '', [
                ['label' => '调出仓', 'value' => $header->from_warehouse],
                ['label' => '调入仓', 'value' => $header->to_warehouse],
                ['label' => '调拨日期', 'value' => $header->transfer_date],
                ['label' => '备注', 'value' => $header->remark],
            ], [['label' => '调拨金额', 'value' => ReportFilter::num($header->total_amount)]]),
            'items' => $items,
            'columns' => $this->drillProductColumns(),
        ]);
    }

    /** 报废报损穿透：报损单表头 + 行明细 */
    private function drillScrap(Request $request): JsonResponse
    {
        $validated = $request->validate(['adjust_no' => 'required|string|max:50']);

        $header = DB::table('stock_adjusts as ar')
            ->leftJoin('warehouses as w', 'w.id', '=', 'ar.warehouse_id')
            ->where('ar.adjust_no', $validated['adjust_no'])
            ->selectRaw("
                ar.adjust_no, ar.adjust_date, COALESCE(w.name, '') as warehouse_name,
                COALESCE(ar.total_qty, 0) as total_qty, COALESCE(ar.total_amount, 0) as total_amount,
                COALESCE(ar.reason, '') as reason
            ")
            ->first();

        if (! $header) {
            return $this->error('报损单不存在', 404);
        }

        $items = DB::table('stock_adjust_items as ari')
            ->where('ari.adjust_id', DB::table('stock_adjusts')->where('adjust_no', $validated['adjust_no'])->value('id'))
            ->selectRaw('
                ari.product_code, ari.product_name, ari.spec, ari.unit,
                ari.before_qty, ari.adjust_qty, ari.after_qty, ari.unit_cost, ari.total_cost
            ')
            ->get();

        return $this->success([
            'header' => $this->drillHeader($header->adjust_no, '', [
                ['label' => '仓库', 'value' => $header->warehouse_name],
                ['label' => '报损日期', 'value' => $header->adjust_date],
                ['label' => '原因', 'value' => $header->reason],
            ], [['label' => '报损金额', 'value' => ReportFilter::num($header->total_amount)]]),
            'items' => $items,
            'columns' => [
                ['prop' => 'product_code', 'label' => '编码', 'width' => 80, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'product_name', 'label' => '商品', 'width' => 120, 'align' => 'left', 'type' => 'text', 'bold' => true],
                ['prop' => 'before_qty', 'label' => '调整前', 'width' => 70, 'align' => 'right', 'type' => 'int'],
                ['prop' => 'adjust_qty', 'label' => '报损数量', 'width' => 80, 'align' => 'right', 'type' => 'int', 'bold' => true],
                ['prop' => 'after_qty', 'label' => '调整后', 'width' => 70, 'align' => 'right', 'type' => 'int'],
                ['prop' => 'unit_cost', 'label' => '成本单价', 'width' => 80, 'align' => 'right', 'type' => 'money'],
                ['prop' => 'total_cost', 'label' => '报损金额', 'width' => 90, 'align' => 'right', 'type' => 'money', 'bold' => true],
            ],
        ]);
    }

    /** 入库汇总穿透：该商品区间内的入库行 */
    private function drillStockInSummary(Request $request): JsonResponse
    {
        $validated = $request->validate(['product_id' => 'required|integer']);

        $product = DB::table('products')->where('id', $validated['product_id'])->first(['id', 'code', 'name']);
        if (! $product) {
            return $this->error('商品不存在', 404);
        }

        $params = ReportFilter::parse($request);
        $header = $this->drillHeader('商品入库明细', $product->code, [
            ['label' => '商品', 'value' => $product->name],
            ['label' => '起止日期', 'value' => ($params['start_date'] ?: '最早').' ~ '.($params['end_date'] ?: '最新')],
        ]);

        $query = DB::table('stock_in_items as si')
            ->join('stock_ins as sih', 'sih.id', '=', 'si.stock_in_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sih.warehouse_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'sih.supplier_id')
            ->where('si.product_id', $validated['product_id']);

        $this->applyDateRange($query, $params, 'sih.stock_date');

        $items = $query
            ->selectRaw("
                sih.order_no, sih.stock_date,
                case sih.type when 'purchase' then '采购入库' when 'adjustment' then '调整入库' else sih.type end as type_label,
                COALESCE(w.name, '') as warehouse_name, COALESCE(s.name, '') as supplier_name,
                si.quantity, si.price, si.amount
            ")
            ->orderByDesc('sih.stock_date')->get();

        return $this->success([
            'header' => $header,
            'items' => $items,
            'columns' => [
                ['prop' => 'stock_date', 'label' => '入库日期', 'width' => 90, 'align' => 'center', 'type' => 'text'],
                ['prop' => 'order_no', 'label' => '入库单号', 'width' => 140, 'align' => 'left', 'type' => 'text', 'bold' => true],
                ['prop' => 'type_label', 'label' => '类型', 'width' => 80, 'align' => 'center', 'type' => 'text'],
                ['prop' => 'warehouse_name', 'label' => '仓库', 'width' => 90, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'supplier_name', 'label' => '供应商', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'quantity', 'label' => '数量', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                ['prop' => 'price', 'label' => '单价', 'width' => 80, 'align' => 'right', 'type' => 'money'],
                ['prop' => 'amount', 'label' => '金额', 'width' => 100, 'align' => 'right', 'type' => 'money', 'bold' => true],
            ],
        ]);
    }

    /** 收发存汇总穿透：该商品区间内的库存流水 */
    private function drillStockDetail(Request $request): JsonResponse
    {
        $validated = $request->validate(['product_id' => 'required|integer']);

        $product = DB::table('products')->where('id', $validated['product_id'])->first(['id', 'code', 'name']);
        if (! $product) {
            return $this->error('商品不存在', 404);
        }

        $params = ReportFilter::parse($request);
        $header = $this->drillHeader('商品库存流水', $product->code, [
            ['label' => '商品', 'value' => $product->name],
            ['label' => '起止日期', 'value' => ($params['start_date'] ?: '最早').' ~ '.($params['end_date'] ?: '最新')],
        ]);

        $query = DB::table('stocks_history as sh')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sh.warehouse_id')
            ->where('sh.product_id', $validated['product_id']);

        if (! empty($params['start_date']) || ! empty($params['end_date'])) {
            $start = $params['start_date'] ? $params['start_date'].' 00:00:00' : '1970-01-01 00:00:00';
            $end = $params['end_date'] ? $params['end_date'].' 23:59:59' : '2999-12-31 23:59:59';
            $query->whereBetween('sh.created_at', [$start, $end]);
        }

        $items = $query
            ->selectRaw("
                sh.created_at, COALESCE(w.name, '') as warehouse_name,
                sh.change_type, sh.change_qty, sh.before_qty, sh.after_qty
            ")
            ->orderByDesc('sh.created_at')->get()
            ->map(fn ($r) => (object) [
                'created_at' => $r->created_at,
                'warehouse_name' => $r->warehouse_name,
                'change_type' => $this->stockChangeLabel($r->change_type),
                'change_qty' => ReportFilter::num($r->change_qty),
                'before_qty' => ReportFilter::num($r->before_qty),
                'after_qty' => ReportFilter::num($r->after_qty),
            ]);

        return $this->success([
            'header' => $header,
            'items' => $items,
            'columns' => [
                ['prop' => 'created_at', 'label' => '时间', 'width' => 140, 'align' => 'center', 'type' => 'text'],
                ['prop' => 'warehouse_name', 'label' => '仓库', 'width' => 90, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'change_type', 'label' => '类型', 'width' => 90, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'change_qty', 'label' => '变动', 'width' => 80, 'align' => 'right', 'type' => 'number', 'sign_color' => true],
                ['prop' => 'before_qty', 'label' => '变动前', 'width' => 80, 'align' => 'right', 'type' => 'int'],
                ['prop' => 'after_qty', 'label' => '变动后', 'width' => 80, 'align' => 'right', 'type' => 'int', 'bold' => true],
            ],
        ]);
    }

    private function stockChangeLabel(string $type): string
    {
        return match ($type) {
            'purchase' => '采购入库',
            'sale' => '销售出库',
            'transfer_out' => '调拨出库',
            'transfer_in' => '调拨入库',
            'stock_loss' => '报损出库',
            'stock_check' => '盘点调整',
            default => $type ?: '—',
        };
    }

    /** 费用查询穿透：费用单自身（单行费用，无行明细，返回一行摘要） */
    private function drillExpense(Request $request): JsonResponse
    {
        $validated = $request->validate(['expense_no' => 'required|string|max:50']);

        $header = DB::table('expenses as e')
            ->leftJoin('employees as emp', 'emp.id', '=', 'e.handler_id')
            ->leftJoin('auth_department as d', 'd.id', '=', 'e.department_id')
            ->where('e.expense_no', $validated['expense_no'])
            ->selectRaw("
                e.expense_no, e.expense_date, e.expense_type,
                COALESCE(emp.name, '') as handler_name,
                COALESCE(d.name, '') as department_name,
                e.amount, COALESCE(e.remark, '') as remark,
                case e.status when 1 then '已生效' when 0 then '草稿' else '其他' end as status_label
            ")
            ->first();

        if (! $header) {
            return $this->error('费用单不存在', 404);
        }

        return $this->success([
            'header' => $this->drillHeader($header->expense_no, '', [
                ['label' => '费用类型', 'value' => $header->expense_type],
                ['label' => '经办人', 'value' => $header->handler_name],
                ['label' => '部门', 'value' => $header->department_name],
                ['label' => '费用日期', 'value' => $header->expense_date],
                ['label' => '状态', 'value' => $header->status_label],
                ['label' => '备注', 'value' => $header->remark],
            ], [['label' => '费用金额', 'value' => ReportFilter::num($header->amount)]]),
            'items' => [['项目' => $header->expense_type, '金额' => ReportFilter::num($header->amount)]],
            'columns' => [
                ['prop' => '项目', 'label' => '费用项目', 'width' => 160, 'align' => 'left', 'type' => 'text'],
                ['prop' => '金额', 'label' => '金额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'bold' => true],
            ],
        ]);
    }

    /** 价格方案穿透：该商品的标准售价/成本价/各等级方案价 */
    private function drillPricePlan(Request $request): JsonResponse
    {
        $validated = $request->validate(['product_id' => 'required|integer']);

        $product = DB::table('products as p')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->where('p.id', $validated['product_id'])
            ->selectRaw("
                p.code, p.name, p.spec, p.price_unit_small as unit,
                COALESCE(b.name, '') as brand_name,
                COALESCE(p.cost_price, 0) as cost_price,
                COALESCE(p.price_small, p.price_large, 0) as base_price
            ")
            ->first();

        if (! $product) {
            return $this->error('商品不存在', 404);
        }

        $header = $this->drillHeader('商品价格方案', $product->code, [
            ['label' => '商品', 'value' => $product->name],
            ['label' => '规格', 'value' => $product->spec],
            ['label' => '品牌', 'value' => $product->brand_name],
        ]);

        $items = [[
            'item' => '标准售价', 'price' => ReportFilter::num($product->base_price), 'note' => '挂牌价',
        ], [
            'item' => '成本价', 'price' => ReportFilter::num($product->cost_price), 'note' => '当前成本',
        ]];

        $levels = DB::table('customer_levels')->where('status', 1)->orderBy('sort')->get(['id', 'name']);
        $levelPrices = DB::table('product_level_prices')->where('product_id', $validated['product_id'])
            ->pluck('price', 'level_id');
        foreach ($levels as $lv) {
            if ($levelPrices->has($lv->id)) {
                $items[] = [
                    'item' => $lv->name.' 方案价', 'price' => ReportFilter::num($levelPrices->get($lv->id)), 'note' => '等级方案',
                ];
            }
        }

        return $this->success([
            'header' => $header,
            'items' => $items,
            'columns' => [
                ['prop' => 'item', 'label' => '价格项目', 'width' => 160, 'align' => 'left', 'type' => 'text'],
                ['prop' => 'price', 'label' => '价格', 'width' => 120, 'align' => 'right', 'type' => 'money', 'bold' => true],
                ['prop' => 'note', 'label' => '说明', 'width' => 100, 'align' => 'left', 'type' => 'text'],
            ],
        ]);
    }

    /** 商品行明细通用列（编码/名称/规格/单位/数量/单价/金额） */
    private function drillProductColumns(): array
    {
        return [
            ['prop' => 'product_code', 'label' => '编码', 'width' => 80, 'align' => 'left', 'type' => 'text'],
            ['prop' => 'product_name', 'label' => '商品', 'width' => 120, 'align' => 'left', 'type' => 'text', 'bold' => true],
            ['prop' => 'spec', 'label' => '规格', 'width' => 70, 'align' => 'left', 'type' => 'text'],
            ['prop' => 'unit', 'label' => '单位', 'width' => 56, 'align' => 'center', 'type' => 'text'],
            ['prop' => 'quantity', 'label' => '数量', 'width' => 76, 'align' => 'right', 'type' => 'int'],
            ['prop' => 'price', 'label' => '单价', 'width' => 80, 'align' => 'right', 'type' => 'money'],
            ['prop' => 'amount', 'label' => '金额', 'width' => 90, 'align' => 'right', 'type' => 'money', 'bold' => true],
        ];
    }

    /**
     * 穿透表头统一结构：通用抽屉按此渲染。
     *
     * title   单据号或实体名（大标题）；code 次要编号（供应商/商品编码 Tag）；
     * desc    [{label,value}] 键值描述；totals [{label,value}] 底部合计（金额右对齐、红色）。
     * 通用组件不感知各业务字段名，只认这套契约。
     */
    private function drillHeader(string $title, string $code = '', array $desc = [], array $totals = []): array
    {
        return [
            'title' => $title,
            'code' => $code,
            'desc' => array_values($desc),
            'totals' => array_values($totals),
        ];
    }

    /** 维度调度：统一返回查询构造器（purchase/stock_detail_summary/price_plan 走 index 里的专路） */
    private function aggregate(array $params, string $dimension)
    {
        return match ($dimension) {
            'stock_in' => $this->stockInQuery($params),
            'transfer' => $this->transferQuery($params),
            'scrap' => $this->scrapQuery($params),
            'stock_in_summary' => $this->stockInSummaryQuery($params),
            'expense' => $this->expenseQuery($params),
            'price_plan' => $this->pricePlanQuery($params),
            default => $this->stockInQuery($params),
        };
    }

    /** 商品公共筛选（品牌/主副分类/商品多选/关键字）——凡是 join 了 products as p 的维度共用 */
    private function applyProductFilters($query, array $params): void
    {
        if (! empty($params['brand_ids'])) {
            $query->whereIn('p.brand_id', $params['brand_ids']);
        }
        if (! empty($params['main_category_ids'])) {
            $query->whereIn('p.main_category_id', $params['main_category_ids']);
        }
        if (! empty($params['sub_category_ids'])) {
            $query->whereIn('p.sub_category_id', $params['sub_category_ids']);
        }
        if (! empty($params['product_ids'])) {
            $query->whereIn('p.id', $params['product_ids']);
        }
        $kw = trim((string) ($params['product_keyword'] ?? ''));
        if ($kw !== '') {
            $query->where(function ($w) use ($kw) {
                $w->where('p.name', 'like', '%'.$kw.'%')->orWhere('p.code', 'like', '%'.$kw.'%');
            });
        }
    }

    /** 日期区间套到指定 date 列（date 类型直接用字符串比较，SQLite/MySQL 方言一致） */
    private function applyDateRange($query, array $params, string $column): void
    {
        if (! empty($params['start_date'])) {
            $query->where($column, '>=', $params['start_date']);
        }
        if (! empty($params['end_date'])) {
            $query->where($column, '<=', $params['end_date']);
        }
    }

    // ================================================================
    // 采购汇总：采购申请单与退货单分表查询，按供应商在 PHP 侧合并
    // ================================================================

    /** 采购额侧：已审核/已转库采购申请单按供应商汇总 */
    private function purchaseMergedRows(array $params): array
    {
        $purchase = DB::table('purchase_applications as pa')
            ->leftJoin('suppliers as s', 's.id', '=', 'pa.supplier_id')
            ->whereIn('pa.status', self::PURCHASE_STATUS)
            ->when(! empty($params['supplier_ids']), fn ($q) => $q->whereIn('pa.supplier_id', $params['supplier_ids']))
            ->when(! empty($params['warehouse_ids']), fn ($q) => $q->whereIn('pa.warehouse_id', $params['warehouse_ids']));

        $this->applyDateRange($purchase, $params, 'pa.apply_date');

        $purchaseMap = $purchase
            // supplier_name 与 supplier_id 同一行，groupBy 一并带上满足 only_full_group_by
            ->groupBy('pa.supplier_id', 'pa.supplier_name')
            ->selectRaw('
                pa.supplier_id, COALESCE(pa.supplier_name, \'\') as supplier_name,
                COALESCE(MAX(s.code), \'\') as supplier_code,
                COUNT(*) as order_count,
                SUM(pa.total_amount) as purchase_amount,
                SUM(pa.total_quantity) as purchase_qty
            ')
            ->get()
            ->keyBy('supplier_id');

        $returns = DB::table('purchase_returns as pr')
            ->where('pr.status', 'approved')
            ->when(! empty($params['supplier_ids']), fn ($q) => $q->whereIn('pr.supplier_id', $params['supplier_ids']))
            ->when(! empty($params['warehouse_ids']), fn ($q) => $q->whereIn('pr.warehouse_id', $params['warehouse_ids']));

        $this->applyDateRange($returns, $params, 'pr.return_date');

        $returnMap = $returns
            ->groupBy('pr.supplier_id')
            ->selectRaw('
                pr.supplier_id,
                COUNT(*) as return_count,
                SUM(pr.total_amount) as return_amount,
                SUM(pr.total_qty) as return_qty
            ')
            ->get()
            ->keyBy('supplier_id');

        // 供应商维度：采购侧为主键，退货但无采购的供应商也出现（退货额非零）
        $ids = $purchaseMap->keys()->merge($returnMap->keys())->filter()->unique()->sort()->values();

        $rows = $ids->map(function ($id) use ($purchaseMap, $returnMap) {
            $p = $purchaseMap->get($id);
            $r = $returnMap->get($id);

            $row = new \stdClass;
            $row->supplier_id = (int) $id;
            $row->supplier_code = $p->supplier_code ?? '';
            $row->supplier_name = $p->supplier_name ?? '';
            $row->order_count = (int) ($p->order_count ?? 0);
            $row->purchase_amount = ReportFilter::num($p->purchase_amount ?? 0);
            $row->purchase_qty = ReportFilter::num($p->purchase_qty ?? 0);
            $row->return_count = (int) ($r->return_count ?? 0);
            $row->return_amount = ReportFilter::num($r->return_amount ?? 0);
            $row->return_qty = ReportFilter::num($r->return_qty ?? 0);
            $row->net_amount = ReportFilter::num($row->purchase_amount - $row->return_amount);
            $row->net_qty = ReportFilter::num($row->purchase_qty - $row->return_qty);

            return $row;
        });

        // 供应商编码/名称补充：仅退货侧存在（无采购单）时从 suppliers 带出
        $needInfo = $rows->filter(fn ($r) => $r->supplier_code === '' && $r->supplier_name === '');
        if ($needInfo->isNotEmpty()) {
            $suppliers = DB::table('suppliers')->whereIn('id', $needInfo->pluck('supplier_id'))->get(['id', 'code', 'name']);
            foreach ($suppliers as $s) {
                foreach ($needInfo as $r) {
                    if ((int) $r->supplier_id === (int) $s->id) {
                        $r->supplier_code = $s->code;
                        $r->supplier_name = $s->name;
                    }
                }
            }
        }

        return $rows
            ->sortByDesc('net_amount')
            ->values()
            ->all();
    }

    // ================================================================
    // 查询构造（其余 7 个维度）
    // ================================================================

    /** 入库明细：stock_ins × items 逐行（含类型 purchase/adjustment，供应商可筛） */
    private function stockInQuery(array $params)
    {
        $query = DB::table('stock_in_items as si')
            ->join('stock_ins as sih', 'sih.id', '=', 'si.stock_in_id')
            ->leftJoin('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sih.warehouse_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'sih.supplier_id');

        $this->applyDateRange($query, $params, 'sih.stock_date');
        if (! empty($params['warehouse_ids'])) {
            $query->whereIn('sih.warehouse_id', $params['warehouse_ids']);
        }
        if (! empty($params['supplier_ids'])) {
            $query->whereIn('sih.supplier_id', $params['supplier_ids']);
        }
        $this->applyProductFilters($query, $params);

        return $query
            ->selectRaw("
                sih.order_no, sih.stock_date,
                case sih.type when 'purchase' then '采购入库' when 'adjustment' then '调整入库' else sih.type end as type_label,
                COALESCE(w.name, '') as warehouse_name,
                COALESCE(s.code, '') as supplier_code, COALESCE(s.name, '') as supplier_name,
                COALESCE(p.code, si.product_id) as product_code,
                COALESCE(p.name, CONCAT('商品#', si.product_id)) as product_name,
                COALESCE(p.spec, '') as spec, COALESCE(p.price_unit_small, '') as unit,
                si.quantity, si.price, si.amount,
                COALESCE(sih.remark, '') as remark, sih.created_at
            ")
            ->orderByDesc('sih.stock_date')->orderByDesc('sih.id')->orderBy('si.id');
    }

    /** 调拨明细：transfers × items 逐行（status in approved/completed） */
    private function transferQuery(array $params)
    {
        $query = DB::table('transfer_items as ti')
            ->join('transfers as t', 't.id', '=', 'ti.transfer_id')
            ->leftJoin('products as p', 'p.id', '=', 'ti.product_id')
            ->leftJoin('warehouses as fw', 'fw.id', '=', 't.from_warehouse_id')
            ->leftJoin('warehouses as tw', 'tw.id', '=', 't.to_warehouse_id')
            ->whereIn('t.status', ['approved', 'completed']);

        $this->applyDateRange($query, $params, 't.transfer_date');
        if (! empty($params['warehouse_ids'])) {
            $query->where(function ($w) use ($params) {
                $w->whereIn('t.from_warehouse_id', $params['warehouse_ids'])
                    ->orWhereIn('t.to_warehouse_id', $params['warehouse_ids']);
            });
        }
        $this->applyProductFilters($query, $params);

        return $query
            ->selectRaw("
                t.order_no, t.transfer_date,
                COALESCE(fw.name, '') as from_warehouse, COALESCE(tw.name, '') as to_warehouse,
                COALESCE(p.code, ti.product_id) as product_code,
                COALESCE(p.name, CONCAT('商品#', ti.product_id)) as product_name,
                COALESCE(p.spec, '') as spec, COALESCE(p.price_unit_small, '') as unit,
                ti.quantity, ti.price, ti.amount,
                COALESCE(t.remark, '') as remark
            ")
            ->orderByDesc('t.transfer_date')->orderByDesc('t.id')->orderBy('ti.id');
    }

    /** 报废报损：库存调整单 adjust_type=stock_loss（已审核）逐行 */
    private function scrapQuery(array $params)
    {
        $query = DB::table('stock_adjust_items as ari')
            ->join('stock_adjusts as ar', 'ar.id', '=', 'ari.adjust_id')
            ->leftJoin('products as p', 'p.id', '=', 'ari.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'ar.warehouse_id')
            ->where('ar.status', 'approved')
            ->where('ar.adjust_type', 'stock_loss');

        $this->applyDateRange($query, $params, 'ar.adjust_date');
        if (! empty($params['warehouse_ids'])) {
            $query->whereIn('ar.warehouse_id', $params['warehouse_ids']);
        }
        $this->applyProductFilters($query, $params);

        return $query
            ->selectRaw("
                ar.adjust_no, ar.adjust_date, COALESCE(w.name, '') as warehouse_name,
                ari.product_code, ari.product_name, ari.spec, ari.unit,
                ari.before_qty, ari.adjust_qty, ari.after_qty,
                ari.unit_cost, ari.total_cost,
                COALESCE(ar.reason, '') as reason
            ")
            ->orderByDesc('ar.adjust_date')->orderByDesc('ar.id')->orderBy('ari.id');
    }

    /** 入库汇总：stock_in_items 按商品汇总数量/金额 */
    private function stockInSummaryQuery(array $params)
    {
        $query = DB::table('stock_in_items as si')
            ->join('stock_ins as sih', 'sih.id', '=', 'si.stock_in_id')
            ->leftJoin('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('product_categories as mc', 'mc.id', '=', 'p.main_category_id');

        $this->applyDateRange($query, $params, 'sih.stock_date');
        if (! empty($params['warehouse_ids'])) {
            $query->whereIn('sih.warehouse_id', $params['warehouse_ids']);
        }
        if (! empty($params['supplier_ids'])) {
            $query->whereIn('sih.supplier_id', $params['supplier_ids']);
        }
        $this->applyProductFilters($query, $params);

        return $query
            ->groupBy('si.product_id', 'p.code', 'p.name', 'p.spec', 'p.price_unit_small', 'b.name', 'mc.name')
            ->selectRaw("
                si.product_id, COALESCE(p.code, '') as product_code,
                COALESCE(p.name, CONCAT('商品#', si.product_id)) as product_name,
                COALESCE(p.spec, '') as spec, COALESCE(p.price_unit_small, '') as unit,
                COALESCE(b.name, '') as brand_name, COALESCE(mc.name, '') as category_name,
                COUNT(DISTINCT sih.id) as in_count,
                SUM(si.quantity) as quantity, SUM(si.amount) as amount
            ")
            ->orderByDesc('amount');
    }

    /** 收发存汇总：商品级期初/收入/发出/期末，流水以 stocks_history 为准 */
    private function stockDetailQuery(array $params)
    {
        // 期末（当前结存）按商品汇总，期间净变动/收入/发出按 stocks_history 汇总
        $stock = DB::table('stocks as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('product_categories as mc', 'mc.id', '=', 'p.main_category_id');

        if (! empty($params['warehouse_ids'])) {
            $stock->whereIn('s.warehouse_id', $params['warehouse_ids']);
        }
        $this->applyProductFilters($stock, $params);

        $closingMap = (clone $stock)
            ->groupBy('p.id', 'p.code', 'p.name', 'p.spec', 'p.price_unit_small', 'b.name', 'mc.name')
            ->selectRaw("
                p.id as product_id, p.code as product_code, p.name as product_name,
                p.spec, p.price_unit_small as unit,
                COALESCE(b.name, '') as brand_name, COALESCE(mc.name, '') as category_name,
                COALESCE(SUM(s.quantity), 0) as closing_qty
            ")
            ->get()
            ->keyBy('product_id');

        $history = DB::table('stocks_history as sh')
            ->leftJoin('products as p', 'p.id', '=', 'sh.product_id');

        // created_at 是 datetime，区间要含整天：起始补 00:00:00、截止补 23:59:59
        if (! empty($params['start_date']) || ! empty($params['end_date'])) {
            $start = $params['start_date'] ? $params['start_date'].' 00:00:00' : '1970-01-01 00:00:00';
            $end = $params['end_date'] ? $params['end_date'].' 23:59:59' : '2999-12-31 23:59:59';
            $history->whereBetween('sh.created_at', [$start, $end]);
        }
        if (! empty($params['warehouse_ids'])) {
            $history->whereIn('sh.warehouse_id', $params['warehouse_ids']);
        }
        $this->applyProductFilters($history, $params);

        $movementMap = (clone $history)
            ->groupBy('sh.product_id')
            ->selectRaw('
                sh.product_id,
                SUM(CASE WHEN sh.change_qty > 0 THEN sh.change_qty ELSE 0 END) as in_qty,
                SUM(CASE WHEN sh.change_qty < 0 THEN ABS(sh.change_qty) ELSE 0 END) as out_qty
            ')
            ->get()
            ->keyBy('product_id');

        // 合并：期末为基线，期间仅变动（in=out=0）的商品也出现；期间有流水但当前无库存的商品补入
        $ids = $closingMap->keys()->merge($movementMap->keys())->unique()->values();

        return collect($ids)->map(function ($id) use ($closingMap, $movementMap) {
            $c = $closingMap->get($id);
            $m = $movementMap->get($id);

            $row = new \stdClass;
            $row->product_id = (int) $id;
            $row->product_code = $c->product_code ?? '';
            $row->product_name = $c->product_name ?? ('商品#'.$id);
            $row->spec = $c->spec ?? '';
            $row->unit = $c->unit ?? '';
            $row->brand_name = $c->brand_name ?? '';
            $row->category_name = $c->category_name ?? '';
            $row->in_qty = ReportFilter::num($m->in_qty ?? 0);
            $row->out_qty = ReportFilter::num($m->out_qty ?? 0);
            $row->closing_qty = ReportFilter::num($c->closing_qty ?? 0);
            // 期初 = 期末 − 收入 + 发出（倒推），口径与 C 机库存流水一致
            $row->opening_qty = ReportFilter::num($row->closing_qty - $row->in_qty + $row->out_qty);

            return $row;
        });
    }

    /** 费用查询：expenses 逐单（费用类型多选，日期/部门/经手人） */
    private function expenseQuery(array $params)
    {
        $query = DB::table('expenses as e')
            ->leftJoin('employees as emp', 'emp.id', '=', 'e.handler_id')
            ->leftJoin('auth_department as d', 'd.id', '=', 'e.department_id');

        $this->applyDateRange($query, $params, 'e.expense_date');
        if (! empty($params['expense_types'])) {
            $query->whereIn('e.expense_type', $params['expense_types']);
        }

        return $query
            ->selectRaw("
                e.expense_no, e.expense_date, e.expense_type,
                COALESCE(emp.name, '') as handler_name,
                COALESCE(d.name, '') as department_name,
                e.amount, COALESCE(e.remark, '') as remark,
                case e.status when 1 then '已生效' when 0 then '草稿' else '其他' end as status_label
            ")
            ->orderByDesc('e.expense_date')->orderByDesc('e.id');
    }

    /** 价格方案：商品 × 等级价宽表（标准售价为基，等级价覆盖）——列是动态的 */
    private function pricePlanQuery(array $params)
    {
        $query = DB::table('products as p')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->where('p.is_active', 1);

        $this->applyProductFilters($query, $params);

        return $query
            ->selectRaw("
                p.id as product_id, p.code as product_code, p.name as product_name,
                p.spec, p.price_unit_small as unit,
                COALESCE(b.name, '') as brand_name,
                COALESCE(p.cost_price, 0) as cost_price,
                COALESCE(p.price_small, p.price_large, 0) as base_price
            ")
            ->orderBy('p.code');
    }

    // ================================================================
    // 分页 / 合计
    // ================================================================

    private function paginateRows($rows, Request $request, array $params): LengthAwarePaginator
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $pageSize = min(500, max(1, (int) ($params['page_size'] ?? 20)));

        return new LengthAwarePaginator(
            $rows->forPage($page, $pageSize)->values(),
            $rows->count(),
            $pageSize,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    }

    /**
     * 合计：对全量行求和，键名与各维度列 prop 对齐（前端 ReportTable 自动挑可合计列）。
     * 费用合计 = 明细之和（E6 验收口径）。
     */
    private function summary($rows, string $dimension): array
    {
        $rows = collect($rows);

        switch ($dimension) {
            case 'purchase':
                return [
                    'purchase_amount' => ReportFilter::num($rows->sum('purchase_amount')),
                    'return_amount' => ReportFilter::num($rows->sum('return_amount')),
                    'net_amount' => ReportFilter::num($rows->sum('net_amount')),
                    'order_count' => (int) $rows->sum('order_count'),
                    'return_count' => (int) $rows->sum('return_count'),
                ];
            case 'stock_in':
                return [
                    'quantity' => ReportFilter::num($rows->sum('quantity')),
                    'amount' => ReportFilter::num($rows->sum('amount')),
                ];
            case 'transfer':
                return [
                    'quantity' => ReportFilter::num($rows->sum('quantity')),
                    'amount' => ReportFilter::num($rows->sum('amount')),
                ];
            case 'scrap':
                return [
                    'adjust_qty' => ReportFilter::num($rows->sum('adjust_qty')),
                    'total_cost' => ReportFilter::num($rows->sum('total_cost')),
                ];
            case 'stock_in_summary':
                return [
                    'quantity' => ReportFilter::num($rows->sum('quantity')),
                    'amount' => ReportFilter::num($rows->sum('amount')),
                    'in_count' => (int) $rows->sum('in_count'),
                ];
            case 'stock_detail_summary':
                return [
                    'opening_qty' => ReportFilter::num($rows->sum('opening_qty')),
                    'in_qty' => ReportFilter::num($rows->sum('in_qty')),
                    'out_qty' => ReportFilter::num($rows->sum('out_qty')),
                    'closing_qty' => ReportFilter::num($rows->sum('closing_qty')),
                ];
            case 'expense':
                return [
                    'amount' => ReportFilter::num($rows->sum('amount')),
                    'count' => $rows->count(),
                ];
            case 'price_plan':
                return [];
        }

        return [];
    }

    // ================================================================
    // 列定义（JSON 驱动，前端 ReportTable 按 type 渲染）
    // ================================================================

    private function columns(string $dimension): array
    {
        switch ($dimension) {
            case 'purchase':
                return [
                    ['prop' => 'supplier_code', 'label' => '供应商编码', 'width' => 120, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'supplier_name', 'label' => '供应商名称', 'width' => 220, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'order_count', 'label' => '采购单数', 'width' => 100, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'purchase_qty', 'label' => '采购数量', 'width' => 110, 'align' => 'right', 'type' => 'number'],
                    ['prop' => 'purchase_amount', 'label' => '采购额', 'width' => 130, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'return_count', 'label' => '退货单数', 'width' => 100, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'return_qty', 'label' => '退货数量', 'width' => 110, 'align' => 'right', 'type' => 'number', 'cell_class' => 'rp-loss'],
                    ['prop' => 'return_amount', 'label' => '退货额', 'width' => 130, 'align' => 'right', 'type' => 'money', 'cell_class' => 'rp-loss'],
                    ['prop' => 'net_qty', 'label' => '净数量', 'width' => 110, 'align' => 'right', 'type' => 'number', 'sign_color' => true],
                    ['prop' => 'net_amount', 'label' => '净额', 'width' => 140, 'align' => 'right', 'type' => 'money', 'bold' => true, 'sign_color' => true],
                ];

            case 'stock_in':
                return [
                    ['prop' => 'stock_date', 'label' => '入库日期', 'width' => 110, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'order_no', 'label' => '入库单号', 'width' => 170, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'type_label', 'label' => '类型', 'width' => 100, 'align' => 'center', 'type' => 'level', 'tag_map' => [
                        '采购入库' => ['label' => '采购入库', 'color' => 'success'],
                        '调整入库' => ['label' => '调整入库', 'color' => 'warning'],
                    ]],
                    ['prop' => 'warehouse_name', 'label' => '仓库', 'width' => 120, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'supplier_code', 'label' => '供应商编码', 'width' => 120, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'supplier_name', 'label' => '供应商名称', 'width' => 200, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 200, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 90, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'unit', 'label' => '单位', 'width' => 70, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'quantity', 'label' => '数量', 'width' => 100, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'price', 'label' => '单价', 'width' => 100, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'amount', 'label' => '金额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'remark', 'label' => '备注', 'width' => 160, 'align' => 'left', 'type' => 'text'],
                ];

            case 'transfer':
                return [
                    ['prop' => 'transfer_date', 'label' => '调拨日期', 'width' => 110, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'order_no', 'label' => '调拨单号', 'width' => 170, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'from_warehouse', 'label' => '调出仓库', 'width' => 130, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'to_warehouse', 'label' => '调入仓库', 'width' => 130, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 200, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 90, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'unit', 'label' => '单位', 'width' => 70, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'quantity', 'label' => '数量', 'width' => 100, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'price', 'label' => '单价', 'width' => 100, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'amount', 'label' => '金额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'remark', 'label' => '备注', 'width' => 160, 'align' => 'left', 'type' => 'text'],
                ];

            case 'scrap':
                return [
                    ['prop' => 'adjust_date', 'label' => '报损日期', 'width' => 110, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'adjust_no', 'label' => '报损单号', 'width' => 170, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'warehouse_name', 'label' => '仓库', 'width' => 120, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 200, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 90, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'unit', 'label' => '单位', 'width' => 70, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'before_qty', 'label' => '调整前', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'adjust_qty', 'label' => '报损数量', 'width' => 100, 'align' => 'right', 'type' => 'int', 'bold' => true, 'cell_class' => 'rp-loss'],
                    ['prop' => 'after_qty', 'label' => '调整后', 'width' => 90, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'unit_cost', 'label' => '成本单价', 'width' => 100, 'align' => 'right', 'type' => 'money'],
                    ['prop' => 'total_cost', 'label' => '报损金额', 'width' => 120, 'align' => 'right', 'type' => 'money', 'bold' => true, 'cell_class' => 'rp-loss'],
                    ['prop' => 'reason', 'label' => '原因', 'width' => 160, 'align' => 'left', 'type' => 'text'],
                ];

            case 'stock_in_summary':
                return [
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 200, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 90, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'unit', 'label' => '单位', 'width' => 70, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'brand_name', 'label' => '品牌', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'category_name', 'label' => '分类', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'in_count', 'label' => '入库单数', 'width' => 100, 'align' => 'right', 'type' => 'int'],
                    ['prop' => 'quantity', 'label' => '入库数量', 'width' => 110, 'align' => 'right', 'type' => 'int', 'bold' => true],
                    ['prop' => 'amount', 'label' => '入库金额', 'width' => 130, 'align' => 'right', 'type' => 'money', 'bold' => true],
                ];

            case 'stock_detail_summary':
                return [
                    ['prop' => 'product_code', 'label' => '商品编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'product_name', 'label' => '商品名称', 'width' => 200, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'spec', 'label' => '规格', 'width' => 90, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'unit', 'label' => '单位', 'width' => 70, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'brand_name', 'label' => '品牌', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'category_name', 'label' => '分类', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'opening_qty', 'label' => '期初结存', 'width' => 110, 'align' => 'right', 'type' => 'int', 'zero_gray' => true],
                    ['prop' => 'in_qty', 'label' => '期间收入', 'width' => 110, 'align' => 'right', 'type' => 'int', 'cell_class' => 'rp-in', 'zero_gray' => true],
                    ['prop' => 'out_qty', 'label' => '期间发出', 'width' => 110, 'align' => 'right', 'type' => 'int', 'cell_class' => 'rp-out', 'zero_gray' => true],
                    ['prop' => 'closing_qty', 'label' => '期末结存', 'width' => 110, 'align' => 'right', 'type' => 'int', 'bold' => true],
                ];

            case 'expense':
                return [
                    ['prop' => 'expense_date', 'label' => '费用日期', 'width' => 110, 'align' => 'center', 'type' => 'text'],
                    ['prop' => 'expense_no', 'label' => '费用单号', 'width' => 170, 'align' => 'left', 'type' => 'text', 'bold' => true],
                    ['prop' => 'expense_type', 'label' => '费用类型', 'width' => 130, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'department_name', 'label' => '部门', 'width' => 130, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'handler_name', 'label' => '经手人', 'width' => 110, 'align' => 'left', 'type' => 'text'],
                    ['prop' => 'amount', 'label' => '金额', 'width' => 130, 'align' => 'right', 'type' => 'money', 'bold' => true],
                    ['prop' => 'status_label', 'label' => '状态', 'width' => 90, 'align' => 'center', 'type' => 'level', 'tag_map' => [
                        '已生效' => ['label' => '已生效', 'color' => 'success'],
                        '草稿' => ['label' => '草稿', 'color' => 'info'],
                    ]],
                    ['prop' => 'remark', 'label' => '备注', 'width' => 200, 'align' => 'left', 'type' => 'text'],
                ];

            case 'price_plan':
                return $this->pricePlanColumns();
        }

        return [];
    }

    /**
     * 价格方案列：商品基础列 + 每个启用客户等级一列（动态）。
     * 等级价列 prop 固定 `level_{id}`，行数据由 pricePlanQuery 后的 enrich 阶段填充。
     */
    private function pricePlanColumns(): array
    {
        $cols = [
            ['prop' => 'product_code', 'label' => '商品编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
            ['prop' => 'product_name', 'label' => '商品名称', 'width' => 200, 'align' => 'left', 'type' => 'text', 'bold' => true],
            ['prop' => 'spec', 'label' => '规格', 'width' => 90, 'align' => 'left', 'type' => 'text'],
            ['prop' => 'unit', 'label' => '单位', 'width' => 70, 'align' => 'center', 'type' => 'text'],
            ['prop' => 'brand_name', 'label' => '品牌', 'width' => 110, 'align' => 'left', 'type' => 'text'],
            ['prop' => 'cost_price', 'label' => '成本价', 'width' => 100, 'align' => 'right', 'type' => 'money'],
            ['prop' => 'base_price', 'label' => '标准售价', 'width' => 110, 'align' => 'right', 'type' => 'money', 'bold' => true],
        ];

        $levels = DB::table('customer_levels')->where('status', 1)->orderBy('sort')->get(['id', 'name', 'code']);
        foreach ($levels as $lv) {
            $cols[] = [
                'prop' => 'level_'.$lv->id,
                'label' => $lv->name,
                'width' => 110,
                'align' => 'right',
                'type' => 'money',
                'tag_map' => null,
            ];
        }

        return $cols;
    }

    /**
     * 价格方案行填充：把 product_level_prices 按商品映射到 level_{id} 列。
     * 由于列是动态的，index() 的 DB 分页在 pricePlanQuery 后需要这里补等级价。
     */
    public function enrichPricePlan($rows): array
    {
        $pids = collect($rows)->pluck('product_id')->filter()->all();
        if (empty($pids)) {
            return collect($rows)->all();
        }

        $levelPrices = DB::table('product_level_prices')
            ->whereIn('product_id', $pids)
            ->get(['product_id', 'level_id', 'price'])
            ->groupBy(fn ($lp) => (string) $lp->product_id);

        return collect($rows)->map(function ($r) use ($levelPrices) {
            $prices = $levelPrices[(string) $r->product_id] ?? collect();
            foreach ($prices as $lp) {
                $key = 'level_'.$lp->level_id;
                $r->{$key} = ReportFilter::num($lp->price);
            }

            return $r;
        })->all();
    }
}
