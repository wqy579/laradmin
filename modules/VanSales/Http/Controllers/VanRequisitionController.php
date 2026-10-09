<?php

namespace Modules\VanSales\Http\Controllers;

use App\Contracts\TaskNotification;
use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Exceptions\StockRuleException;
use Modules\Stock\Services\StockService;
use Modules\VanSales\Models\VanRequisition;
use Modules\VanSales\Models\VanRequisitionItem;

/**
 * 车销要货申请（VanRequisition）
 *
 * 业务员根据当日销售计划向仓库申请装车商品，库管员审核通过后冻结源仓库存，
 * 再由 VanPickingController 拣货装车（解冻+出库+车上仓入库）。
 *
 * 状态机：draft → pending → approved → picked(拣货完成终态) / rejected → draft / cancelled
 *
 * 模板对照：StockAdjustController（generateNo/applyFilters/csvCell/writeOperationLog/applyApproval）
 */
class VanRequisitionController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private StockService $stockService,
        private TaskNotification $notifier,
    ) {}

    /** 指定源仓的全部商品库存（新增要货单时加载明细用） */
    public function warehouseProducts(Request $request)
    {
        $request->validate(['warehouse_id' => 'required|exists:warehouses,id']);
        $warehouseId = (int) $request->input('warehouse_id');

        $products = DB::table('stocks as s')
            ->join('products as p', 's.product_id', '=', 'p.id')
            ->where('s.warehouse_id', $warehouseId)
            ->where('p.is_active', 1)
            ->orderBy('p.name')
            ->get([
                's.product_id', 'p.code as product_code', 'p.name as product_name',
                'p.spec', 'p.price_unit_small as unit',
                's.quantity as stock_qty', 's.frozen_qty', 's.cost_price',
            ])
            ->map(function ($r) {
                $r->stock_qty = (int) $r->stock_qty;
                $r->available_qty = (int) $r->stock_qty - (int) $r->frozen_qty;
                $r->cost_price = (float) $r->cost_price;

                return $r;
            });

        return $this->success(['list' => $products, 'total' => $products->count()]);
    }

    /** 列表（分页 + 单号/业务员/车辆/状态/日期筛选） */
    public function index(Request $request)
    {
        $query = $this->applyFilters(VanRequisition::with(['vehicle', 'warehouse']), $request);

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        return $this->paginated($paginator);
    }

    /** 导出 CSV */
    public function export(Request $request)
    {
        $list = $this->applyFilters(VanRequisition::with(['vehicle', 'warehouse']), $request)
            ->orderByDesc('id')->get();

        $csv = "\u{FEFF}车销要货申请列表\n\n";
        $csv .= "申请日期,要货单号,业务员,车牌号,源仓库,商品总数,总金额,状态,制单人,审核人\n";

        foreach ($list as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $r->apply_date?->format('Y-m-d') ?? '',
                $this->csvCell($r->requisition_no),
                $this->csvCell($r->salesman_name ?? ''),
                $this->csvCell($r->vehicle?->plate_no ?? ''),
                $this->csvCell($r->warehouse?->name ?? ''),
                $r->total_qty,
                number_format((float) $r->total_amount, 2, '.', ''),
                $this->statusLabel($r->status),
                $this->csvCell($r->creator_name ?? ''),
                $this->csvCell($r->approver_name ?? '')
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="van_requisitions.csv"',
        ]);
    }

    /** 列表/导出共用的筛选条件 */
    private function applyFilters($query, Request $request)
    {
        if ($request->filled('requisition_no')) {
            $query->where('requisition_no', 'like', '%'.trim((string) $request->input('requisition_no')).'%');
        }
        if ($request->filled('salesman_id')) {
            $query->where('salesman_id', (int) $request->input('salesman_id'));
        }
        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', (int) $request->input('vehicle_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('start_date')) {
            $query->whereDate('apply_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('apply_date', '<=', $request->input('end_date'));
        }

        return $query;
    }

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
            'pending' => '待审核',
            'approved' => '已审核',
            'picked' => '已拣货',
            'rejected' => '已驳回',
            'cancelled' => '已取消',
            default => $status,
        };
    }

    /** 详情（含明细） */
    public function show($id)
    {
        $requisition = VanRequisition::with(['items.product', 'vehicle', 'warehouse'])->find($id);
        if (! $requisition) {
            return $this->notFound('要货申请不存在');
        }

        return $this->success($requisition);
    }

    /** 创建草稿 */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'salesman_id' => 'nullable|integer',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'apply_date' => 'nullable|date',
            'expected_date' => 'nullable|date',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.apply_qty' => 'required|integer|min:1',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.remark' => 'nullable|string|max:255',
        ]);

        [$adminId, $adminName] = $this->currentAdmin();

        return DB::transaction(function () use ($validated, $adminId, $adminName) {
            $warehouseId = (int) $validated['warehouse_id'];

            $stockMap = DB::table('stocks')->where('warehouse_id', $warehouseId)->get()->keyBy('product_id');
            $productIds = collect($validated['items'])->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
            $productMap = DB::table('products')->whereIn('id', $productIds)->get()->keyBy('id');

            $totalQty = 0;
            $totalAmount = 0.0;
            $itemRows = [];
            $sort = 0;

            foreach ($validated['items'] as $item) {
                $productId = (int) $item['product_id'];
                $product = $productMap->get($productId);
                if (! $product) {
                    continue;
                }
                $stock = $stockMap->get($productId);
                $stockQty = (int) ($stock?->quantity ?? 0);
                $available = $stockQty - (int) ($stock?->frozen_qty ?? 0);
                $applyQty = (int) $item['apply_qty'];
                if ($applyQty > $available) {
                    return $this->error("商品【{$product->name}】申请数量{$applyQty}超过可用库存{$available}", 422);
                }
                $unitCost = (float) ($item['unit_cost'] ?? ($stock?->cost_price ?? 0));
                $amount = round($applyQty * $unitCost, 2);

                $totalQty += $applyQty;
                $totalAmount += $amount;

                $itemRows[] = [
                    'product_id' => $productId,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'spec' => $product->spec,
                    'unit' => $product->price_unit_small,
                    'apply_qty' => $applyQty,
                    'stock_qty' => $stockQty,
                    'unit_cost' => $unitCost,
                    'amount' => $amount,
                    'remark' => $item['remark'] ?? null,
                    'sort' => $sort,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $sort++;
            }

            if (empty($itemRows)) {
                return $this->error('没有有效的商品明细', 422);
            }

            $requisition = VanRequisition::create([
                'requisition_no' => $this->generateNo('VHQ', 'van_requisitions', 'requisition_no'),
                'salesman_id' => $validated['salesman_id'] ?? $adminId,
                'salesman_name' => $adminName,
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'warehouse_id' => $warehouseId,
                'apply_date' => $validated['apply_date'] ?? now()->toDateString(),
                'expected_date' => $validated['expected_date'] ?? null,
                'status' => VanRequisition::STATUS_DRAFT,
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
                'remark' => $validated['remark'] ?? null,
                'created_by' => $adminId,
                'creator_name' => $adminName,
            ]);

            foreach ($itemRows as &$row) {
                $row['requisition_id'] = $requisition->id;
            }
            unset($row);
            VanRequisitionItem::insert($itemRows);

            return $this->created($requisition->load('items', 'vehicle', 'warehouse'), '草稿已保存');
        });
    }

    /** 更新草稿（仅 draft/rejected 可改） */
    public function update(Request $request, $id)
    {
        $requisition = VanRequisition::find($id);
        if (! $requisition) {
            return $this->notFound('要货申请不存在');
        }
        if (! in_array($requisition->status, [VanRequisition::STATUS_DRAFT, VanRequisition::STATUS_REJECTED], true)) {
            return $this->error('当前状态不能修改', 422);
        }

        $validated = $request->validate([
            'salesman_id' => 'nullable|integer',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'apply_date' => 'nullable|date',
            'expected_date' => 'nullable|date',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.apply_qty' => 'required|integer|min:1',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.remark' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($requisition, $validated) {
            $warehouseId = (int) $validated['warehouse_id'];
            $stockMap = DB::table('stocks')->where('warehouse_id', $warehouseId)->get()->keyBy('product_id');
            $productIds = collect($validated['items'])->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
            $productMap = DB::table('products')->whereIn('id', $productIds)->get()->keyBy('id');

            $totalQty = 0;
            $totalAmount = 0.0;
            $itemRows = [];
            $sort = 0;

            foreach ($validated['items'] as $item) {
                $productId = (int) $item['product_id'];
                $product = $productMap->get($productId);
                if (! $product) {
                    continue;
                }
                $stock = $stockMap->get($productId);
                $applyQty = (int) $item['apply_qty'];
                $unitCost = (float) ($item['unit_cost'] ?? ($stock?->cost_price ?? 0));
                $amount = round($applyQty * $unitCost, 2);
                $totalQty += $applyQty;
                $totalAmount += $amount;

                $itemRows[] = [
                    'requisition_id' => $requisition->id,
                    'product_id' => $productId,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'spec' => $product->spec,
                    'unit' => $product->price_unit_small,
                    'apply_qty' => $applyQty,
                    'stock_qty' => (int) ($stock?->quantity ?? 0),
                    'unit_cost' => $unitCost,
                    'amount' => $amount,
                    'remark' => $item['remark'] ?? null,
                    'sort' => $sort,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $sort++;
            }

            if (empty($itemRows)) {
                return $this->error('没有有效的商品明细', 422);
            }

            $requisition->items()->delete();
            VanRequisitionItem::insert($itemRows);
            $requisition->update([
                'salesman_id' => $validated['salesman_id'] ?? $requisition->salesman_id,
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'warehouse_id' => $warehouseId,
                'apply_date' => $validated['apply_date'] ?? $requisition->apply_date?->toDateString() ?? now()->toDateString(),
                'expected_date' => $validated['expected_date'] ?? $requisition->expected_date,
                'remark' => array_key_exists('remark', $validated) ? $validated['remark'] : $requisition->remark,
                'status' => VanRequisition::STATUS_DRAFT,
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
            ]);

            return $this->success($requisition->load('items', 'vehicle', 'warehouse'), '草稿已更新');
        });
    }

    /** 删除草稿（仅 draft） */
    public function destroy($id)
    {
        $requisition = VanRequisition::find($id);
        if (! $requisition) {
            return $this->notFound('要货申请不存在');
        }
        if ($requisition->status !== VanRequisition::STATUS_DRAFT) {
            return $this->error('当前状态不能删除', 422);
        }

        $requisition->items()->delete();
        $requisition->delete();

        return $this->success(null, '已删除');
    }

    /** 提交审核（draft → pending） */
    public function submit($id)
    {
        $requisition = VanRequisition::with('items')->find($id);
        if (! $requisition) {
            return $this->notFound('要货申请不存在');
        }
        if ($requisition->status !== VanRequisition::STATUS_DRAFT) {
            return $this->error('当前状态不能提交', 422);
        }
        if ($requisition->items->isEmpty()) {
            return $this->error('要货单没有明细，请先添加商品', 422);
        }

        $requisition->update(['status' => VanRequisition::STATUS_PENDING]);
        [$adminId, $adminName] = $this->currentAdmin();
        $this->writeOperationLog($requisition, $adminId, $adminName, 'submit', '提交审核', VanRequisition::STATUS_DRAFT, VanRequisition::STATUS_PENDING);

        return $this->success($requisition, '已提交审核');
    }

    /** 审核通过（pending → approved）：冻结源仓库存 */
    public function approve(Request $request, $id)
    {
        $validated = $request->validate(['approval_comment' => 'nullable|string|max:500']);

        $requisition = VanRequisition::with('items')->find($id);
        if (! $requisition) {
            return $this->notFound('要货申请不存在');
        }
        if ($requisition->status === VanRequisition::STATUS_APPROVED) {
            return $this->success($requisition, '已审核，无需重复操作');
        }
        if ($requisition->status !== VanRequisition::STATUS_PENDING) {
            return $this->error('只有待审核的要货单才能审核', 422);
        }

        [$adminId, $adminName] = $this->currentAdmin();

        try {
            DB::transaction(function () use ($requisition, $adminId, $adminName, $validated) {
                // 冻结源仓库存（拣货装车时解冻）
                foreach ($requisition->items as $item) {
                    $this->stockService->freeze(
                        (int) $item->product_id,
                        (int) $requisition->warehouse_id,
                        (int) $item->apply_qty,
                        (int) $requisition->id
                    );
                }

                $requisition->update([
                    'status' => VanRequisition::STATUS_APPROVED,
                    'approved_by' => $adminId,
                    'approver_name' => $adminName,
                    'approved_at' => now(),
                    'approval_comment' => $validated['approval_comment'] ?? null,
                ]);
                $this->writeOperationLog($requisition, $adminId, $adminName, 'approve', '审核通过', VanRequisition::STATUS_PENDING, VanRequisition::STATUS_APPROVED);
            });
        } catch (StockRuleException $e) {
            return $this->error('审核失败：'.$e->getMessage().'，请调整申请数量后重试', 422);
        }

        return $this->success($requisition->load('items', 'vehicle', 'warehouse'), '审核通过，库存已冻结，可拣货装车');
    }

    /** 驳回（pending → draft） */
    public function reject(Request $request, $id)
    {
        $comment = $request->input('comment', $request->input('approval_comment'));

        $requisition = VanRequisition::find($id);
        if (! $requisition) {
            return $this->notFound('要货申请不存在');
        }
        if ($requisition->status !== VanRequisition::STATUS_PENDING) {
            return $this->error('只有待审核的要货单才能驳回', 422);
        }

        $requisition->update(['status' => VanRequisition::STATUS_DRAFT, 'approval_comment' => $comment]);
        [$adminId, $adminName] = $this->currentAdmin();
        $this->writeOperationLog($requisition, $adminId, $adminName, 'reject', '驳回', VanRequisition::STATUS_PENDING, VanRequisition::STATUS_DRAFT);

        return $this->success($requisition, '已驳回，可继续修改');
    }

    /** 取消（draft/pending → cancelled） */
    public function cancel($id)
    {
        $requisition = VanRequisition::find($id);
        if (! $requisition) {
            return $this->notFound('要货申请不存在');
        }
        if (! in_array($requisition->status, [VanRequisition::STATUS_DRAFT, VanRequisition::STATUS_PENDING], true)) {
            return $this->error('当前状态不能取消', 422);
        }

        $requisition->update(['status' => VanRequisition::STATUS_CANCELLED]);

        return $this->success($requisition, '已取消');
    }

    private function writeOperationLog(VanRequisition $requisition, ?int $adminId, string $adminName, string $action, string $actionLabel, ?string $fromStatus, ?string $toStatus, ?string $detail = null): void
    {
        DB::table('order_operation_logs')->insert([
            'order_id' => $requisition->id,
            'order_no' => $requisition->requisition_no,
            'order_type' => 'van_requisition',
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

    private function currentAdmin(): array
    {
        $admin = auth('admin')->user();

        return [$admin?->id, $admin?->real_name ?? $admin?->username ?? '管理员'];
    }

    private function generateNo(string $prefix, string $table, string $column): string
    {
        $like = $prefix.date('Ymd').'%';
        $last = DB::table($table)->where($column, 'like', $like)->orderByDesc($column)->value($column);
        $seq = $last ? intval(substr($last, -6)) + 1 : 1;

        return $prefix.date('Ymd').str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
