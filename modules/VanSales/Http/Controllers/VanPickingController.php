<?php

namespace Modules\VanSales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Exceptions\StockRuleException;
use Modules\Stock\Services\StockService;
use Modules\VanSales\Models\VanPicking;
use Modules\VanSales\Models\VanPickingItem;
use Modules\VanSales\Models\VanRequisition;

/**
 * 车销拣货（VanPicking）——含验货（字段合并到明细表，不单独建验货表）
 *
 * 库管员按已审核的要货申请拣货装车：源仓出库 + 车上仓入库 + 解冻要货冻结量。
 * 验货：验货员核对拣货数量，差异≠0 必填备注，异常退回重拣。
 *
 * 状态机：draft → pending → approved(装车完成) / cancelled
 *         approved 后 checked 由验货动作推进（checked_at/checker）
 *
 * 模板对照：DeliveryController::dispatch（装车事务+catch StockRuleException）
 */
class VanPickingController extends Controller
{
    use ResponseTrait;

    public function __construct(private StockService $stockService) {}

    /** 列表 */
    public function index(Request $request)
    {
        $query = $this->applyFilters(VanPicking::with(['requisition', 'vehicle', 'vehicleWarehouse']), $request);

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        return $this->paginated($paginator);
    }

    /** 导出 */
    public function export(Request $request)
    {
        $list = $this->applyFilters(VanPicking::with(['requisition', 'vehicle', 'vehicleWarehouse']), $request)
            ->orderByDesc('id')->get();

        $csv = "\u{FEFF}车销拣货单列表\n\n";
        $csv .= "拣货单号,要货单号,拣货日期,车牌号,拣货数量,状态,是否验货\n";

        foreach ($list as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s\n",
                $this->csvCell($r->picking_no),
                $this->csvCell($r->requisition?->requisition_no ?? ''),
                $r->pick_date?->format('Y-m-d') ?? '',
                $this->csvCell($r->vehicle?->plate_no ?? ''),
                $r->total_qty ?? 0,
                $this->statusLabel($r->status),
                $r->checked ? '已验货' : '未验货'
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="van_picking.csv"',
        ]);
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('picking_no')) {
            $query->where('picking_no', 'like', '%'.trim((string) $request->input('picking_no')).'%');
        }
        if ($request->filled('requisition_id')) {
            $query->where('requisition_id', (int) $request->input('requisition_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('start_date')) {
            $query->whereDate('pick_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('pick_date', '<=', $request->input('end_date'));
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
            'approved' => '已装车',
            'cancelled' => '已取消',
            default => $status,
        };
    }

    /** 详情（含明细） */
    public function show($id)
    {
        $picking = VanPicking::with(['items.product', 'requisition', 'vehicle', 'vehicleWarehouse'])->find($id);
        if (! $picking) {
            return $this->notFound('拣货单不存在');
        }

        return $this->success($picking);
    }

    /** 创建拣货单（从已审核的要货申请复制明细） */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'requisition_id' => 'required|exists:van_requisitions,id',
            'pick_date' => 'nullable|date',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.pick_qty' => 'required|integer|min:0',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.location' => 'nullable|string|max:50',
            'items.*.remark' => 'nullable|string|max:255',
        ]);

        $requisition = VanRequisition::with('items')->find($validated['requisition_id']);
        if (! $requisition) {
            return $this->notFound('要货申请不存在');
        }
        if ($requisition->status !== VanRequisition::STATUS_APPROVED) {
            return $this->error('只有已审核的要货申请才能创建拣货单', 422);
        }

        [$adminId, $adminName] = $this->currentAdmin();

        // 车辆仓 ID：从要货申请的车辆解析对应的 type='vehicle' warehouse
        $vehicleWarehouseId = $requisition->vehicle_id
            ? DB::table('warehouses')->where('vehicle_id', $requisition->vehicle_id)->value('id')
            : null;
        if (! $vehicleWarehouseId) {
            return $this->error('该要货申请未关联车辆或车辆仓未生成，请先在车辆管理中创建车辆', 422);
        }

        return DB::transaction(function () use ($validated, $requisition, $adminId, $adminName, $vehicleWarehouseId) {
            $productMap = $requisition->items->keyBy('product_id');

            $totalQty = 0;
            $itemRows = [];
            $sort = 0;

            foreach ($validated['items'] as $item) {
                $productId = (int) $item['product_id'];
                $reqItem = $productMap->get($productId);
                if (! $reqItem) {
                    continue;
                }
                $pickQty = (int) $item['pick_qty'];
                if ($pickQty < 0) {
                    return $this->error('拣货数量不能为负', 422);
                }
                $unitCost = (float) ($item['unit_cost'] ?? $reqItem->unit_cost);
                $amount = round($pickQty * $unitCost, 2);
                $totalQty += $pickQty;

                $itemRows[] = [
                    'picking_id' => 0, // 占位，创建后回填
                    'product_id' => $productId,
                    'product_code' => $reqItem->product_code,
                    'product_name' => $reqItem->product_name,
                    'spec' => $reqItem->spec,
                    'unit' => $reqItem->unit,
                    'apply_qty' => (int) $reqItem->apply_qty,
                    'pick_qty' => $pickQty,
                    'unit_cost' => $unitCost,
                    'amount' => $amount,
                    'location' => $item['location'] ?? null,
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

            $picking = VanPicking::create([
                'picking_no' => $this->generateNo('VHP', 'van_picking', 'picking_no'),
                'requisition_id' => $requisition->id,
                'warehouse_id' => $requisition->warehouse_id,
                'vehicle_id' => $requisition->vehicle_id,
                'vehicle_warehouse_id' => $vehicleWarehouseId,
                'picker_id' => $adminId,
                'picker_name' => $adminName,
                'pick_date' => $validated['pick_date'] ?? now()->toDateString(),
                'status' => VanPicking::STATUS_DRAFT,
                'total_qty' => $totalQty,
                'remark' => $validated['remark'] ?? null,
            ]);

            foreach ($itemRows as &$row) {
                $row['picking_id'] = $picking->id;
            }
            unset($row);
            VanPickingItem::insert($itemRows);

            return $this->created($picking->load('items', 'requisition', 'vehicle', 'vehicleWarehouse'), '拣货单已创建');
        });
    }

    /** 更新拣货明细（仅 draft） */
    public function update(Request $request, $id)
    {
        $picking = VanPicking::find($id);
        if (! $picking) {
            return $this->notFound('拣货单不存在');
        }
        if ($picking->status !== VanPicking::STATUS_DRAFT) {
            return $this->error('当前状态不能修改', 422);
        }

        $validated = $request->validate([
            'pick_date' => 'nullable|date',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.pick_qty' => 'required|integer|min:0',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.location' => 'nullable|string|max:50',
            'items.*.remark' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($picking, $validated) {
            $productIds = collect($validated['items'])->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
            $productMap = DB::table('products')->whereIn('id', $productIds)->get()->keyBy('id');
            $reqItems = $picking->requisition?->items?->keyBy('product_id') ?? collect();

            $totalQty = 0;
            $itemRows = [];
            $sort = 0;

            foreach ($validated['items'] as $item) {
                $productId = (int) $item['product_id'];
                $product = $productMap->get($productId);
                $reqItem = $reqItems->get($productId);
                if (! $product) {
                    continue;
                }
                $pickQty = (int) $item['pick_qty'];
                $unitCost = (float) ($item['unit_cost'] ?? ($reqItem?->unit_cost ?? 0));
                $amount = round($pickQty * $unitCost, 2);
                $totalQty += $pickQty;

                $itemRows[] = [
                    'picking_id' => $picking->id,
                    'product_id' => $productId,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'spec' => $product->spec,
                    'unit' => $product->price_unit_small,
                    'apply_qty' => (int) ($reqItem?->apply_qty ?? 0),
                    'pick_qty' => $pickQty,
                    'unit_cost' => $unitCost,
                    'amount' => $amount,
                    'location' => $item['location'] ?? null,
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

            $picking->items()->delete();
            VanPickingItem::insert($itemRows);
            $picking->update([
                'pick_date' => $validated['pick_date'] ?? $picking->pick_date?->toDateString() ?? now()->toDateString(),
                'remark' => array_key_exists('remark', $validated) ? $validated['remark'] : $picking->remark,
                'total_qty' => $totalQty,
            ]);

            return $this->success($picking->load('items', 'requisition', 'vehicle', 'vehicleWarehouse'), '拣货单已更新');
        });
    }

    /** 删除（仅 draft） */
    public function destroy($id)
    {
        $picking = VanPicking::find($id);
        if (! $picking) {
            return $this->notFound('拣货单不存在');
        }
        if ($picking->status !== VanPicking::STATUS_DRAFT) {
            return $this->error('当前状态不能删除', 422);
        }

        $picking->items()->delete();
        $picking->delete();

        return $this->success(null, '已删除');
    }

    /** 提交（draft → pending，待装车确认） */
    public function submit($id)
    {
        $picking = VanPicking::with('items')->find($id);
        if (! $picking) {
            return $this->notFound('拣货单不存在');
        }
        if ($picking->status !== VanPicking::STATUS_DRAFT) {
            return $this->error('当前状态不能提交', 422);
        }
        if ($picking->items->isEmpty()) {
            return $this->error('拣货单没有明细', 422);
        }

        $picking->update(['status' => VanPicking::STATUS_PENDING]);

        return $this->success($picking, '已提交，待确认装车');
    }

    /**
     * 确认装车（pending → approved）：事务内 源仓出库 + 车上仓入库 + 解冻要货量。
     * 模板对照：DeliveryController::dispatch L191。
     */
    public function approve(Request $request, $id)
    {
        $picking = VanPicking::with(['items', 'requisition'])->find($id);
        if (! $picking) {
            return $this->notFound('拣货单不存在');
        }
        if ($picking->status === VanPicking::STATUS_APPROVED) {
            return $this->success($picking, '已装车，无需重复操作');
        }
        if ($picking->status !== VanPicking::STATUS_PENDING) {
            return $this->error('只有待确认的拣货单才能装车', 422);
        }

        [$adminId, $adminName] = $this->currentAdmin();

        try {
            DB::transaction(function () use ($picking, $adminId, $adminName) {
                foreach ($picking->items as $item) {
                    $pickQty = (int) $item->pick_qty;
                    if ($pickQty <= 0) {
                        continue;
                    }
                    // 源仓出库
                    $this->stockService->stockOut(
                        (int) $item->product_id,
                        (int) $picking->warehouse_id,
                        $pickQty,
                        (int) $picking->id,
                        'VanPicking',
                        '车销装车出库'
                    );
                    // 车上仓入库
                    $this->stockService->stockIn(
                        (int) $item->product_id,
                        (int) $picking->vehicle_warehouse_id,
                        $pickQty,
                        (float) $item->unit_cost,
                        (int) $picking->id,
                        'VanPicking'
                    );
                }

                // 解冻要货申请冻结量
                if ($picking->requisition) {
                    foreach ($picking->requisition->items as $reqItem) {
                        $this->stockService->unfreeze(
                            (int) $reqItem->product_id,
                            (int) $picking->requisition->warehouse_id,
                            (int) $reqItem->apply_qty,
                            (int) $picking->requisition->id
                        );
                    }
                    $picking->requisition->update(['status' => VanRequisition::STATUS_PICKED]);
                }

                $picking->update(['status' => VanPicking::STATUS_APPROVED]);
                $this->writeOperationLog($picking, $adminId, $adminName, 'approve', '装车完成', VanPicking::STATUS_PENDING, VanPicking::STATUS_APPROVED);
            });
        } catch (StockRuleException $e) {
            return $this->error('装车失败：'.$e->getMessage().'，请检查库存后重试', 422);
        }

        return $this->success($picking->load('items', 'requisition', 'vehicle', 'vehicleWarehouse'), '装车完成，车上库存已更新');
    }

    /** 取消 */
    public function cancel($id)
    {
        $picking = VanPicking::find($id);
        if (! $picking) {
            return $this->notFound('拣货单不存在');
        }
        if (! in_array($picking->status, [VanPicking::STATUS_DRAFT, VanPicking::STATUS_PENDING], true)) {
            return $this->error('当前状态不能取消', 422);
        }

        $picking->update(['status' => VanPicking::STATUS_CANCELLED]);

        return $this->success($picking, '已取消');
    }

    /**
     * 验货：更新 check_qty/diff_qty/diff_remark，标记 checked。
     * 差异≠0 必填 diff_remark，否则 422。异常退回重拣（status→draft）。
     */
    public function check(Request $request, $id)
    {
        $picking = VanPicking::with('items')->find($id);
        if (! $picking) {
            return $this->notFound('拣货单不存在');
        }
        if ($picking->status !== VanPicking::STATUS_APPROVED) {
            return $this->error('只有已装车的拣货单才能验货', 422);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.check_qty' => 'required|integer|min:0',
            'items.*.diff_remark' => 'nullable|string|max:500',
            'abnormal' => 'nullable|boolean', // 异常退回重拣
        ]);

        [$adminId, $adminName] = $this->currentAdmin();

        return DB::transaction(function () use ($picking, $validated, $adminId, $adminName) {
            $itemMap = $picking->items->keyBy('product_id');
            $hasAbnormal = false;

            foreach ($validated['items'] as $input) {
                $productId = (int) $input['product_id'];
                $item = $itemMap->get($productId);
                if (! $item) {
                    continue;
                }
                $checkQty = (int) $input['check_qty'];
                $pickQty = (int) $item->pick_qty;
                $diffQty = $checkQty - $pickQty;

                if ($diffQty !== 0) {
                    $hasAbnormal = true;
                    if (empty(trim((string) ($input['diff_remark'] ?? '')))) {
                        return $this->error("商品【{$item->product_name}】验货差异{$diffQty}，必须填写差异备注", 422);
                    }
                }

                $item->update([
                    'check_qty' => $checkQty,
                    'diff_qty' => $diffQty,
                    'diff_remark' => $input['diff_remark'] ?? null,
                ]);
            }

            // 异常退回重拣
            if (! empty($validated['abnormal']) || $hasAbnormal) {
                $picking->update([
                    'status' => VanPicking::STATUS_DRAFT,
                    'checked' => false,
                ]);

                return $this->success($picking->load('items'), '验货发现差异，已退回重拣');
            }

            $picking->update([
                'checked' => true,
                'checker_id' => $adminId,
                'checker_name' => $adminName,
                'checked_at' => now(),
            ]);

            return $this->success($picking->load('items'), '验货通过，商品已正式装车');
        });
    }

    private function writeOperationLog(VanPicking $picking, ?int $adminId, string $adminName, string $action, string $actionLabel, ?string $fromStatus, ?string $toStatus, ?string $detail = null): void
    {
        DB::table('order_operation_logs')->insert([
            'order_id' => $picking->id,
            'order_no' => $picking->picking_no,
            'order_type' => 'van_picking',
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
