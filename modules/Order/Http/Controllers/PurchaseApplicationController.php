<?php

namespace Modules\Order\Http\Controllers;

use App\Contracts\TaskNotification;
use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Order\Models\PurchaseApplication;
use Modules\Order\Models\PurchaseApplicationItem;
use Modules\Stock\Models\Product;
use Modules\Stock\Services\StockService;
use Modules\Business\Models\Employee;

/**
 * 采购申请（PurchaseApplication）
 *
 * 采购流程起点：创建草稿 → 提交审批 → 审批通过/驳回 → 转采购入库。
 *
 * 状态机：draft → pending → approved / rejected / cancelled / transferred
 *   - rejected 编辑保存后回到 draft（用户决策）
 *   - transferred 为终态
 *
 * 模板对照：
 *   - PurchaseReturnController：状态机/store/approve/reject/cancel/batchApprove/export 结构
 *   - StockAdjustController：generateNo/applyFilters/csvCell/writeOperationLog 模式
 *   - StockInController::computeItemQtyAmount：三档数量/单价折算逻辑
 *
 * 转入库（transfer）：
 *   - 调 StockService::stockIn() 写 stocks + stocks_history（related_type='PurchaseApplication'）
 *   - suppliers.balance increment 增加应付
 *   - 不写 stock_ins 表（已弃用，且避免 BusinessHistory 重复计入资金流）
 */
class PurchaseApplicationController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private StockService $stockService,
        private TaskNotification $notifier,
    ) {}

    /** 列表（分页 + 单号/供应商/状态/审批人/日期/关键词筛选） */
    public function index(Request $request)
    {
        $query = $this->applyFilters(PurchaseApplication::query(), $request);

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        return $this->paginated($paginator);
    }

    /**
     * 导出 CSV：与列表同一套筛选条件，不带分页。
     */
    public function export(Request $request)
    {
        $list = $this->applyFilters(PurchaseApplication::query(), $request)
            ->orderByDesc('id')
            ->get();

        $csv = "\u{FEFF}"; // BOM：Excel 正确识别 UTF-8
        $csv .= "采购申请列表\n\n";
        $csv .= "申请单号,供应商,付款类型,制单日期,需用日期,总数量,总金额,状态,制单人,审批人,审批时间,转入库时间,备注\n";

        foreach ($list as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $this->csvCell($r->apply_no),
                $this->csvCell($r->supplier_name ?? ''),
                $this->paymentLabel($r->payment_type),
                $r->apply_date?->format('Y-m-d') ?? '',
                $r->expected_date?->format('Y-m-d') ?? '',
                $r->total_quantity,
                number_format((float) $r->total_amount, 2, '.', ''),
                $this->statusLabel($r->status),
                $this->csvCell($r->creator_name ?? ''),
                $this->csvCell($r->approver_name ?? ''),
                $r->approved_at?->format('Y-m-d H:i') ?? '',
                $r->transferred_at?->format('Y-m-d H:i') ?? '',
                $this->csvCell($r->remark ?? '')
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="purchase_applications.csv"',
        ]);
    }

    /** 详情（含明细 + 操作日志） */
    public function show($id)
    {
        $application = PurchaseApplication::with(['items.product', 'supplier'])->find($id);
        if (! $application) {
            return $this->notFound('采购申请不存在');
        }

        // 操作日志时间线
        $application->operation_logs = DB::table('order_operation_logs')
            ->where('order_type', 'purchase_application')
            ->where('order_id', $id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return $this->success($application);
    }

    /** 创建草稿（支持 submit_for_approval 直接进 pending） */
    public function store(Request $request)
    {
        $validated = $this->validateApplication($request);

        $admin = auth('admin')->user();
        $adminId = $admin?->id;
        $adminName = $admin?->real_name ?? ($admin?->username ?? '管理员');

        return DB::transaction(function () use ($validated, $adminId, $adminName) {
            [$itemRows, $totals] = $this->buildItemRows($validated['items']);
            if (empty($itemRows)) {
                return $this->error('没有有效的商品明细', 422);
            }

            $supplier = DB::table('suppliers')->where('id', $validated['supplier_id'])->first();
            $approverName = null;
            if (! empty($validated['approver_id'])) {
                $emp = Employee::find($validated['approver_id']);
                $approverName = $emp?->name;
            }

            $submitNow = (bool) ($validated['submit_for_approval'] ?? false);
            $status = $submitNow ? PurchaseApplication::STATUS_PENDING : PurchaseApplication::STATUS_DRAFT;

            $application = PurchaseApplication::create([
                'apply_no' => $this->generateNo('CGSQ', 'purchase_applications', 'apply_no'),
                'supplier_id' => $validated['supplier_id'],
                'supplier_name' => $supplier?->name,
                'warehouse_id' => $validated['warehouse_id'] ?? null,
                'apply_date' => $validated['apply_date'] ?? now()->toDateString(),
                'expected_date' => $validated['expected_date'] ?? null,
                'payment_type' => $validated['payment_type'] ?? 'cash',
                'approver_id' => $validated['approver_id'] ?? null,
                'approver_name' => $approverName,
                'status' => $status,
                'total_skus' => count($itemRows),
                'total_qty_large' => $totals['qty_large'],
                'total_qty_medium' => $totals['qty_medium'],
                'total_qty_small' => $totals['qty_small'],
                'total_quantity' => $totals['quantity'],
                'total_amount' => round($totals['amount'], 2),
                'attachment' => $validated['attachment'] ?? null,
                'remark' => $validated['remark'] ?? null,
                'created_by' => $adminId,
                'creator_name' => $adminName,
            ]);

            foreach ($itemRows as &$row) {
                $row['application_id'] = $application->id;
            }
            unset($row);
            PurchaseApplicationItem::insert($itemRows);

            $this->writeOperationLog($application, $adminId, $adminName, 'create', '创建采购申请', null, $status);

            if ($submitNow) {
                $this->writeOperationLog($application, $adminId, $adminName, 'submit', '提交审核', PurchaseApplication::STATUS_DRAFT, PurchaseApplication::STATUS_PENDING);
                $this->notifyApprover($application);
            }

            return $this->created($application->load('items.product'), $submitNow ? '已提交审批' : '草稿已保存');
        });
    }

    /** 更新（仅 draft/rejected 可编辑；rejected 保存后回 draft） */
    public function update(Request $request, $id)
    {
        $application = PurchaseApplication::find($id);
        if (! $application) {
            return $this->notFound('采购申请不存在');
        }
        if (! in_array($application->status, [PurchaseApplication::STATUS_DRAFT, PurchaseApplication::STATUS_REJECTED], true)) {
            return $this->error('当前状态不能修改', 422);
        }

        $validated = $this->validateApplication($request, false);

        return DB::transaction(function () use ($application, $validated) {
            [$itemRows, $totals] = $this->buildItemRows($validated['items']);
            if (empty($itemRows)) {
                return $this->error('没有有效的商品明细', 422);
            }

            $supplier = DB::table('suppliers')->where('id', $validated['supplier_id'])->first();
            $approverName = null;
            if (! empty($validated['approver_id'])) {
                $emp = Employee::find($validated['approver_id']);
                $approverName = $emp?->name;
            }

            $application->items()->delete();
            PurchaseApplicationItem::insert($itemRows);

            $application->update([
                'supplier_id' => $validated['supplier_id'],
                'supplier_name' => $supplier?->name,
                'warehouse_id' => $validated['warehouse_id'] ?? null,
                'apply_date' => $validated['apply_date'] ?? $application->apply_date?->toDateString() ?? now()->toDateString(),
                'expected_date' => $validated['expected_date'] ?? null,
                'payment_type' => $validated['payment_type'] ?? 'cash',
                'approver_id' => $validated['approver_id'] ?? null,
                'approver_name' => $approverName,
                'status' => PurchaseApplication::STATUS_DRAFT,
                'total_skus' => count($itemRows),
                'total_qty_large' => $totals['qty_large'],
                'total_qty_medium' => $totals['qty_medium'],
                'total_qty_small' => $totals['qty_small'],
                'total_quantity' => $totals['quantity'],
                'total_amount' => round($totals['amount'], 2),
                'attachment' => $validated['attachment'] ?? null,
                'remark' => $validated['remark'] ?? null,
            ]);

            [$adminId, $adminName] = $this->currentAdmin();
            $this->writeOperationLog($application, $adminId, $adminName, 'edit', '修改采购申请（驳回后编辑，回到草稿）', PurchaseApplication::STATUS_REJECTED, PurchaseApplication::STATUS_DRAFT);

            return $this->success($application->load('items.product'), '已保存，回到草稿状态');
        });
    }

    /** 删除（仅 draft） */
    public function destroy($id)
    {
        $application = PurchaseApplication::find($id);
        if (! $application) {
            return $this->notFound('采购申请不存在');
        }
        if ($application->status !== PurchaseApplication::STATUS_DRAFT) {
            return $this->error('当前状态不能删除', 422);
        }

        $application->items()->delete();
        $application->delete();

        return $this->success(null, '已删除');
    }

    /** 提交审批（draft → pending） */
    public function submit($id)
    {
        $application = PurchaseApplication::with('items')->find($id);
        if (! $application) {
            return $this->notFound('采购申请不存在');
        }
        if ($application->status !== PurchaseApplication::STATUS_DRAFT) {
            return $this->error('当前状态不能提交', 422);
        }
        if ($application->items->isEmpty()) {
            return $this->error('采购申请没有明细，请先添加商品', 422);
        }

        $application->update(['status' => PurchaseApplication::STATUS_PENDING]);

        [$adminId, $adminName] = $this->currentAdmin();
        $this->writeOperationLog($application, $adminId, $adminName, 'submit', '提交审核', PurchaseApplication::STATUS_DRAFT, PurchaseApplication::STATUS_PENDING);
        $this->notifyApprover($application);

        return $this->success($application, '已提交审批');
    }

    /** 审批通过（pending → approved；不动库存） */
    public function approve(Request $request, $id)
    {
        $validated = $request->validate(['approval_comment' => 'nullable|string|max:500']);

        $application = PurchaseApplication::find($id);
        if (! $application) {
            return $this->notFound('采购申请不存在');
        }
        if ($application->status === PurchaseApplication::STATUS_APPROVED) {
            return $this->success($application, '已审批，无需重复操作');
        }
        if ($application->status !== PurchaseApplication::STATUS_PENDING) {
            return $this->error('只有待审批的采购申请才能审批', 422);
        }

        [$adminId, $adminName] = $this->currentAdmin();

        $application->update([
            'status' => PurchaseApplication::STATUS_APPROVED,
            'approved_by' => $adminId,
            'approved_at' => now(),
            'approval_comment' => $validated['approval_comment'] ?? null,
        ]);

        $this->writeOperationLog(
            $application, $adminId, $adminName, 'approve', '采购申请审批通过',
            PurchaseApplication::STATUS_PENDING, PurchaseApplication::STATUS_APPROVED,
            '采购申请审批通过，申请金额¥'.number_format((float) $application->total_amount, 2)
        );
        $this->notifyCreator($application, '采购申请已审批通过', "采购申请 {$application->apply_no} 已审批通过，可转采购入库");

        return $this->success($application->load('items.product'), '审批通过');
    }

    /** 审批驳回（pending → rejected） */
    public function reject(Request $request, $id)
    {
        $comment = $request->input('comment', $request->input('approval_comment'));
        if (empty(trim((string) $comment))) {
            return $this->error('驳回必须填写原因', 422);
        }

        $application = PurchaseApplication::find($id);
        if (! $application) {
            return $this->notFound('采购申请不存在');
        }
        if ($application->status !== PurchaseApplication::STATUS_PENDING) {
            return $this->error('只有待审批的采购申请才能驳回', 422);
        }

        $application->update([
            'status' => PurchaseApplication::STATUS_REJECTED,
            'approval_comment' => $comment,
        ]);

        [$adminId, $adminName] = $this->currentAdmin();
        $this->writeOperationLog($application, $adminId, $adminName, 'reject', '采购申请驳回', PurchaseApplication::STATUS_PENDING, PurchaseApplication::STATUS_REJECTED, '驳回原因：'.$comment);
        $this->notifyCreator($application, '采购申请被驳回', "采购申请 {$application->apply_no} 被驳回，原因：{$comment}。可修改后重新提交");

        return $this->success($application, '已驳回，可修改后重新提交');
    }

    /** 取消（draft/pending/approved → cancelled） */
    public function cancel($id)
    {
        $application = PurchaseApplication::find($id);
        if (! $application) {
            return $this->notFound('采购申请不存在');
        }
        if (! in_array($application->status, [PurchaseApplication::STATUS_DRAFT, PurchaseApplication::STATUS_PENDING, PurchaseApplication::STATUS_APPROVED], true)) {
            return $this->error('当前状态不能取消', 422);
        }

        $fromStatus = $application->status;
        $application->update(['status' => PurchaseApplication::STATUS_CANCELLED]);

        [$adminId, $adminName] = $this->currentAdmin();
        $this->writeOperationLog($application, $adminId, $adminName, 'cancel', '采购申请取消', $fromStatus, PurchaseApplication::STATUS_CANCELLED);

        return $this->success($application, '已取消');
    }

    /**
     * 转采购入库（approved → transferred）
     *
     * 事务内：调 StockService::stockIn() 写 stocks + stocks_history + syncProductStockQty，
     * suppliers.balance increment 增加应付，更新状态，写操作日志，通知制单人。
     * 不写 stock_ins 表（已弃用，避免 BusinessHistory 重复计入资金流）。
     */
    public function transfer($id)
    {
        $application = PurchaseApplication::with('items')->lockForUpdate()->find($id);
        if (! $application) {
            return $this->notFound('采购申请不存在');
        }
        if ($application->status === PurchaseApplication::STATUS_TRANSFERRED) {
            return $this->success($application, '已转入库，无需重复操作');
        }
        if ($application->status !== PurchaseApplication::STATUS_APPROVED) {
            return $this->error('只有已审批的采购申请才能转入库', 422);
        }
        if ($application->transferred_at !== null) {
            return $this->error('该申请已转入库，请勿重复操作', 422);
        }
        if (empty($application->warehouse_id)) {
            return $this->error('请先指定转入库仓库', 422);
        }

        [$adminId, $adminName] = $this->currentAdmin();
        $warehouseId = (int) $application->warehouse_id;
        $totalAmount = (float) $application->total_amount;

        try {
            DB::transaction(function () use ($application, $adminId, $adminName, $warehouseId, $totalAmount) {
                foreach ($application->items as $item) {
                    $quantity = (int) $item->quantity;
                    if ($quantity <= 0) {
                        continue;
                    }
                    $this->stockService->stockIn(
                        (int) $item->product_id,
                        $warehouseId,
                        $quantity,
                        (float) $item->cost_price,
                        (int) $application->id,
                        'PurchaseApplication'
                    );
                }

                // 增加应付（suppliers.balance increment）
                if ($application->supplier_id) {
                    DB::table('suppliers')
                        ->where('id', $application->supplier_id)
                        ->increment('balance', $totalAmount);
                }

                $application->update([
                    'status' => PurchaseApplication::STATUS_TRANSFERRED,
                    'transferred_by' => $adminId,
                    'transferred_at' => now(),
                    'payable_amount' => $totalAmount,
                ]);

                $this->writeOperationLog(
                    $application, $adminId, $adminName, 'transfer', '转采购入库',
                    PurchaseApplication::STATUS_APPROVED, PurchaseApplication::STATUS_TRANSFERRED,
                    '转采购入库，入库¥'.number_format($totalAmount, 2).'，应付供应商¥'.number_format($totalAmount, 2)
                );
            });
        } catch (\Exception $e) {
            return $this->error('转入库失败：'.$e->getMessage(), 422);
        }

        $this->notifyCreator($application, '采购申请已转入库', "采购申请 {$application->apply_no} 已转入库，库存增加，应付¥".number_format($totalAmount, 2).'已生成');

        return $this->success($application->load('items.product'), '已转入库，库存增加并生成应付');
    }

    /** 批量提交（仅 draft） */
    public function batchSubmit(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        [$adminId, $adminName] = $this->currentAdmin();
        $apps = PurchaseApplication::with('items')->whereIn('id', $validated['ids'])->orderBy('id')->get();

        $result = DB::transaction(function () use ($apps, $adminId, $adminName) {
            $submitted = 0;
            $skipped = [];
            foreach ($apps as $app) {
                if ($app->status !== PurchaseApplication::STATUS_DRAFT) {
                    $skipped[] = ['id' => $app->id, 'apply_no' => $app->apply_no, 'reason' => '非草稿'];
                    continue;
                }
                if ($app->items->isEmpty()) {
                    $skipped[] = ['id' => $app->id, 'apply_no' => $app->apply_no, 'reason' => '无明细'];
                    continue;
                }
                $app->update(['status' => PurchaseApplication::STATUS_PENDING]);
                $this->writeOperationLog($app, $adminId, $adminName, 'submit', '批量提交审核', PurchaseApplication::STATUS_DRAFT, PurchaseApplication::STATUS_PENDING);
                $this->notifyApprover($app);
                $submitted++;
            }

            return ['submitted' => $submitted, 'skipped' => $skipped];
        });

        return $this->success($result, "批量提交完成：成功 {$result['submitted']} 张，跳过 ".count($result['skipped']).' 张');
    }

    /** 批量审批（仅 pending；不转库存） */
    public function batchApprove(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'approval_comment' => 'nullable|string|max:500',
            'remark' => 'nullable|string|max:500',
        ]);
        $comment = $validated['approval_comment'] ?? $validated['remark'] ?? null;

        [$adminId, $adminName] = $this->currentAdmin();
        $apps = PurchaseApplication::whereIn('id', $validated['ids'])->orderBy('id')->get();

        $result = DB::transaction(function () use ($apps, $adminId, $adminName, $comment) {
            $approved = 0;
            $skipped = [];
            foreach ($apps as $app) {
                if ($app->status === PurchaseApplication::STATUS_APPROVED) {
                    $skipped[] = ['id' => $app->id, 'apply_no' => $app->apply_no, 'reason' => '已审批'];
                    continue;
                }
                if ($app->status !== PurchaseApplication::STATUS_PENDING) {
                    $skipped[] = ['id' => $app->id, 'apply_no' => $app->apply_no, 'reason' => '非待审批'];
                    continue;
                }
                $app->update([
                    'status' => PurchaseApplication::STATUS_APPROVED,
                    'approved_by' => $adminId,
                    'approved_at' => now(),
                    'approval_comment' => $comment,
                ]);
                $this->writeOperationLog($app, $adminId, $adminName, 'approve', '批量审批通过', PurchaseApplication::STATUS_PENDING, PurchaseApplication::STATUS_APPROVED);
                $this->notifyCreator($app, '采购申请已审批通过', "采购申请 {$app->apply_no} 已审批通过");
                $approved++;
            }

            return ['approved' => $approved, 'skipped' => $skipped];
        });

        return $this->success($result, "批量审批完成：成功 {$result['approved']} 张，跳过 ".count($result['skipped']).' 张');
    }

    // =========================================================================
    // 私有辅助方法
    // =========================================================================

    /** 列表/导出共用的筛选条件 */
    private function applyFilters($query, Request $request)
    {
        if ($request->filled('apply_no')) {
            $query->where('apply_no', 'like', '%'.trim((string) $request->input('apply_no')).'%');
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', (int) $request->input('supplier_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('approver_id')) {
            $query->where('approver_id', (int) $request->input('approver_id'));
        }
        if ($request->filled('start_date')) {
            $query->whereDate('apply_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('apply_date', '<=', $request->input('end_date'));
        }
        if ($request->filled('keyword')) {
            $kw = '%'.trim((string) $request->input('keyword')).'%';
            $query->where(function ($q) use ($kw) {
                $q->where('apply_no', 'like', $kw)->orWhere('supplier_name', 'like', $kw);
            });
        }

        return $query;
    }

    /** 表单校验（store/update 共用） */
    private function validateApplication(Request $request, bool $isStore = true): array
    {
        return $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'apply_date' => 'nullable|date',
            'expected_date' => 'nullable|date',
            'payment_type' => 'nullable|in:cash,transfer,monthly,other',
            'approver_id' => 'nullable|exists:employees,id',
            'attachment' => 'nullable|array',
            'remark' => 'nullable|string|max:500',
            'submit_for_approval' => 'nullable|boolean',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty_large' => 'nullable|integer|min:0',
            'items.*.qty_medium' => 'nullable|integer|min:0',
            'items.*.qty_small' => 'nullable|integer|min:0',
            'items.*.price_large' => 'nullable|numeric|min:0',
            'items.*.price_medium' => 'nullable|numeric|min:0',
            'items.*.price_small' => 'nullable|numeric|min:0',
            'items.*.remark' => 'nullable|string|max:255',
            'items.*.sort' => 'nullable|integer',
        ]);
    }

    /**
     * 构建明细行 + 汇总（三档折算，对齐 StockInController::computeItemQtyAmount）。
     * 前端传的三档数量/单价不可信，后端一律重算 quantity/amount/cost_price。
     */
    private function buildItemRows(array $items): array
    {
        $productIds = collect($items)->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
        $productMap = Product::whereIn('id', $productIds)->get()->keyBy('id');

        $itemRows = [];
        $totals = ['qty_large' => 0, 'qty_medium' => 0, 'qty_small' => 0, 'quantity' => 0, 'amount' => 0.0];
        $sort = 0;

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $product = $productMap->get($productId);
            if (! $product) {
                continue;
            }

            $qtyLarge = (int) ($item['qty_large'] ?? 0);
            $qtyMedium = (int) ($item['qty_medium'] ?? 0);
            $qtySmall = (int) ($item['qty_small'] ?? 0);
            $priceLarge = (float) ($item['price_large'] ?? 0);
            $priceMedium = (float) ($item['price_medium'] ?? 0);
            $priceSmall = (float) ($item['price_small'] ?? 0);

            $c = (int) ($product->unit_conversion ?? 0);
            $mc = (int) ($product->unit_conversion_medium ?? 0);
            $quantity = $qtyLarge * $c + $qtyMedium * $mc + $qtySmall;

            // 金额 = 三档数量×对应单价之和
            $amount = round($qtyLarge * $priceLarge + $qtyMedium * $priceMedium + $qtySmall * $priceSmall, 2);

            // 主单位成本价：大>中>小 取第一个非零（用于 StockService::stockIn 的 costPrice）
            $costPrice = $qtyLarge > 0 && $priceLarge > 0 ? $priceLarge
                : ($qtyMedium > 0 && $priceMedium > 0 ? $priceMedium
                : ($priceSmall > 0 ? $priceSmall : 0));

            $totals['qty_large'] += $qtyLarge;
            $totals['qty_medium'] += $qtyMedium;
            $totals['qty_small'] += $qtySmall;
            $totals['quantity'] += $quantity;
            $totals['amount'] += $amount;

            $itemRows[] = [
                'product_id' => $productId,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'spec' => $product->spec,
                'unit_large' => $product->price_unit,
                'unit_medium' => $product->price_unit_small,
                'unit_small' => $product->price_unit_small,
                'unit_conversion' => $c,
                'unit_conversion_medium' => $mc,
                'qty_large' => $qtyLarge,
                'qty_medium' => $qtyMedium,
                'qty_small' => $qtySmall,
                'price_large' => $priceLarge,
                'price_medium' => $priceMedium,
                'price_small' => $priceSmall,
                'quantity' => $quantity,
                'amount' => $amount,
                'cost_price' => $costPrice,
                'remark' => $item['remark'] ?? null,
                'sort' => $item['sort'] ?? $sort,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $sort++;
        }

        return [$itemRows, $totals];
    }

    /** 写操作日志（order_operation_logs，对齐 PurchaseReturnController 字段集） */
    private function writeOperationLog(PurchaseApplication $app, ?int $adminId, string $adminName, string $action, string $actionLabel, ?string $fromStatus, ?string $toStatus, ?string $detail = null): void
    {
        DB::table('order_operation_logs')->insert([
            'order_id' => $app->id,
            'order_no' => $app->apply_no,
            'order_type' => 'purchase_application',
            'user_id' => $adminId,
            'user_name' => $adminName,
            'operator_id' => $adminId,
            'operator_name' => $adminName,
            'action' => $action,
            'action_label' => $actionLabel,
            'detail' => $detail,
            'remark' => null,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** 通知审批人（经 employees.user_id 转 auth_user.id） */
    private function notifyApprover(PurchaseApplication $app): void
    {
        if (empty($app->approver_id)) {
            return; // 未指定审批人，靠列表筛选让用户主动查看
        }
        $emp = Employee::find($app->approver_id);
        $userId = $emp?->user_id;
        if (! $userId) {
            return; // 员工未关联系统账号，静默跳过
        }
        try {
            $this->notifier->sendToUser(
                (int) $userId,
                "采购申请待审批 {$app->apply_no}",
                "供应商：{$app->supplier_name}，金额：¥".number_format((float) $app->total_amount, 2),
                'task',
                'task',
                ['action_type' => 'purchase_application', 'id' => $app->id]
            );
        } catch (\Throwable $e) {
            // 通知失败不影响主流程
        }
    }

    /** 通知制单人（created_by 即 auth_user.id） */
    private function notifyCreator(PurchaseApplication $app, string $title, string $content): void
    {
        if (! $app->created_by) {
            return;
        }
        try {
            $this->notifier->sendToUser((int) $app->created_by, $title, $content, 'info', 'task', ['action_type' => 'purchase_application', 'id' => $app->id]);
        } catch (\Throwable $e) {
            // 通知失败不影响主流程
        }
    }

    /** CSV 单元格转义 */
    private function csvCell(?string $value): string
    {
        $value = (string) $value;
        if (preg_match('/[",\n\r]/', $value)) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => '草稿',
            'pending' => '待审批',
            'approved' => '已审批',
            'rejected' => '已驳回',
            'cancelled' => '已取消',
            'transferred' => '已转入库',
            default => $status,
        };
    }

    private function paymentLabel(string $type): string
    {
        return match ($type) {
            'cash' => '现金',
            'transfer' => '转账',
            'monthly' => '月结',
            'other' => '其他',
            default => $type,
        };
    }

    /** 当前登录管理员 [id, 显示名] */
    private function currentAdmin(): array
    {
        $admin = auth('admin')->user();

        return [$admin?->id, $admin?->real_name ?? ($admin?->username ?? '管理员')];
    }

    /** 单号：前缀+Ymd+6位序号（对齐 StockAdjustController::generateNo） */
    private function generateNo(string $prefix, string $table, string $column): string
    {
        $like = $prefix.date('Ymd').'%';
        $last = DB::table($table)->where($column, 'like', $like)->orderByDesc($column)->value($column);
        $seq = $last ? intval(substr($last, -6)) + 1 : 1;

        return $prefix.date('Ymd').str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
