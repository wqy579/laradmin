<?php

namespace Modules\VanSales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Exceptions\StockRuleException;
use Modules\Stock\Services\StockService;
use Modules\VanSales\Models\VanReturnToWarehouse;
use Modules\VanSales\Models\VanReturnToWarehouseItem;

/**
 * 车上退仓（VanReturnToWarehouse，VRW）：车辆把车上库存退回普通仓库。
 *
 * 方向与 VanPickingController::approve 相反——这里是 车上仓出库 + 普通仓入库。
 * 状态机：draft → pending → approved / rejected → draft / cancelled
 *
 * 模板对照：VanRequisitionController（generateNo/applyFilters/csvCell/writeOperationLog）
 */
class VanReturnToWarehouseController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private StockService $stockService,
    ) {}

    /** 指定车上仓的全部商品库存（新增退仓单时加载明细用） */
    public function vehicleStock(Request $request)
    {
        $request->validate(['vehicle_warehouse_id' => 'required|exists:warehouses,id']);
        $warehouseId = (int) $request->input('vehicle_warehouse_id');

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
                $r->frozen_qty = (int) $r->frozen_qty;
                $r->available_qty = $r->stock_qty - $r->frozen_qty;
                $r->cost_price = (float) $r->cost_price;

                return $r;
            });

        return $this->success(['list' => $products, 'total' => $products->count()]);
    }

    /** 列表（分页 + 单号/业务员/车辆/状态/日期筛选） */
    public function index(Request $request)
    {
        $query = $this->applyFilters(VanReturnToWarehouse::with(['vehicle', 'vehicleWarehouse', 'warehouse']), $request);

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        return $this->paginated($paginator);
    }

    /** 导出 CSV */
    public function export(Request $request)
    {
        $list = $this->applyFilters(VanReturnToWarehouse::with(['vehicle', 'vehicleWarehouse', 'warehouse']), $request)
            ->orderByDesc('id')->get();

        $csv = "\u{FEFF}车上退仓列表\n\n";
        $csv .= "退仓日期,退仓单号,业务员,车牌号,车上仓,目的仓库,商品总数,总金额,状态,制单人,审核人\n";

        foreach ($list as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $r->return_date?->format('Y-m-d') ?? '',
                $this->csvCell($r->return_no),
                $this->csvCell($r->salesman_name ?? ''),
                $this->csvCell($r->vehicle?->plate_no ?? ''),
                $this->csvCell($r->vehicleWarehouse?->name ?? ''),
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
            'Content-Disposition' => 'attachment; filename="van_return_to_warehouse.csv"',
        ]);
    }

    /** 列表/导出共用的筛选条件 */
    private function applyFilters($query, Request $request)
    {
        if ($request->filled('return_no')) {
            $query->where('return_no', 'like', '%'.trim((string) $request->input('return_no')).'%');
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
            $query->whereDate('return_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('return_date', '<=', $request->input('end_date'));
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
            'rejected' => '已驳回',
            'cancelled' => '已取消',
            default => $status,
        };
    }

    /** 详情（含明细） */
    public function show($id)
    {
        $order = VanReturnToWarehouse::with(['items.product', 'vehicle', 'vehicleWarehouse', 'warehouse'])->find($id);
        if (! $order) {
            return $this->notFound('退仓单不存在');
        }

        return $this->success($order);
    }

    /** 创建草稿 */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'salesman_id' => 'nullable|integer',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'vehicle_warehouse_id' => 'required|exists:warehouses,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'return_date' => 'nullable|date',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.return_qty' => 'required|integer|min:1',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.remark' => 'nullable|string|max:255',
        ]);

        if ((int) $validated['vehicle_warehouse_id'] === (int) $validated['warehouse_id']) {
            return $this->error('车上仓与目的仓库不能相同', 422);
        }

        [$adminId, $adminName] = $this->currentAdmin();

        return DB::transaction(function () use ($validated, $adminId, $adminName) {
            $vehicleWarehouseId = (int) $validated['vehicle_warehouse_id'];

            $stockMap = DB::table('stocks')->where('warehouse_id', $vehicleWarehouseId)->get()->keyBy('product_id');
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
                $returnQty = (int) $item['return_qty'];
                if ($returnQty > $available) {
                    return $this->error("商品【{$product->name}】退仓数量{$returnQty}超过车上可用库存{$available}", 422);
                }
                $unitCost = (float) ($item['unit_cost'] ?? ($stock?->cost_price ?? 0));
                $amount = round($returnQty * $unitCost, 2);

                $totalQty += $returnQty;
                $totalAmount += $amount;

                $itemRows[] = [
                    'product_id' => $productId,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'spec' => $product->spec,
                    'unit' => $product->price_unit_small,
                    'return_qty' => $returnQty,
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

            $order = VanReturnToWarehouse::create([
                'return_no' => $this->generateNo('VRW', 'van_return_to_warehouse', 'return_no'),
                'salesman_id' => $validated['salesman_id'] ?? $adminId,
                'salesman_name' => $adminName,
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'vehicle_warehouse_id' => $vehicleWarehouseId,
                'warehouse_id' => (int) $validated['warehouse_id'],
                'return_date' => $validated['return_date'] ?? now()->toDateString(),
                'status' => VanReturnToWarehouse::STATUS_DRAFT,
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
                'remark' => $validated['remark'] ?? null,
                'created_by' => $adminId,
                'creator_name' => $adminName,
            ]);

            foreach ($itemRows as &$row) {
                $row['order_id'] = $order->id;
            }
            unset($row);
            VanReturnToWarehouseItem::insert($itemRows);

            return $this->created($order->load('items', 'vehicle', 'vehicleWarehouse', 'warehouse'), '草稿已保存');
        });
    }

    /** 更新草稿（仅 draft/rejected 可改） */
    public function update(Request $request, $id)
    {
        $order = VanReturnToWarehouse::find($id);
        if (! $order) {
            return $this->notFound('退仓单不存在');
        }
        if (! in_array($order->status, [VanReturnToWarehouse::STATUS_DRAFT, 'rejected'], true)) {
            return $this->error('当前状态不能修改', 422);
        }

        $validated = $request->validate([
            'salesman_id' => 'nullable|integer',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'vehicle_warehouse_id' => 'required|exists:warehouses,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'return_date' => 'nullable|date',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.return_qty' => 'required|integer|min:1',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.remark' => 'nullable|string|max:255',
        ]);

        if ((int) $validated['vehicle_warehouse_id'] === (int) $validated['warehouse_id']) {
            return $this->error('车上仓与目的仓库不能相同', 422);
        }

        return DB::transaction(function () use ($order, $validated) {
            $vehicleWarehouseId = (int) $validated['vehicle_warehouse_id'];
            $stockMap = DB::table('stocks')->where('warehouse_id', $vehicleWarehouseId)->get()->keyBy('product_id');
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
                $returnQty = (int) $item['return_qty'];
                $unitCost = (float) ($item['unit_cost'] ?? ($stock?->cost_price ?? 0));
                $amount = round($returnQty * $unitCost, 2);
                $totalQty += $returnQty;
                $totalAmount += $amount;

                $itemRows[] = [
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'spec' => $product->spec,
                    'unit' => $product->price_unit_small,
                    'return_qty' => $returnQty,
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

            $order->items()->delete();
            VanReturnToWarehouseItem::insert($itemRows);
            $order->update([
                'salesman_id' => $validated['salesman_id'] ?? $order->salesman_id,
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'vehicle_warehouse_id' => $vehicleWarehouseId,
                'warehouse_id' => (int) $validated['warehouse_id'],
                'return_date' => $validated['return_date'] ?? $order->return_date?->toDateString() ?? now()->toDateString(),
                'remark' => array_key_exists('remark', $validated) ? $validated['remark'] : $order->remark,
                'status' => VanReturnToWarehouse::STATUS_DRAFT,
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
            ]);

            return $this->success($order->load('items', 'vehicle', 'vehicleWarehouse', 'warehouse'), '草稿已更新');
        });
    }

    /** 删除草稿（仅 draft） */
    public function destroy($id)
    {
        $order = VanReturnToWarehouse::find($id);
        if (! $order) {
            return $this->notFound('退仓单不存在');
        }
        if ($order->status !== VanReturnToWarehouse::STATUS_DRAFT) {
            return $this->error('当前状态不能删除', 422);
        }

        $order->items()->delete();
        $order->delete();

        return $this->success(null, '已删除');
    }

    /** 提交审核（draft → pending） */
    public function submit($id)
    {
        $order = VanReturnToWarehouse::with('items')->find($id);
        if (! $order) {
            return $this->notFound('退仓单不存在');
        }
        if ($order->status !== VanReturnToWarehouse::STATUS_DRAFT) {
            return $this->error('当前状态不能提交', 422);
        }
        if ($order->items->isEmpty()) {
            return $this->error('退仓单没有明细，请先添加商品', 422);
        }

        $order->update(['status' => 'pending']);
        [$adminId, $adminName] = $this->currentAdmin();
        $this->writeOperationLog($order, $adminId, $adminName, 'submit', '提交审核', VanReturnToWarehouse::STATUS_DRAFT, 'pending');

        return $this->success($order, '已提交审核');
    }

    /**
     * 审核通过（pending → approved）：车上仓出库 + 目的普通仓入库。
     *
     * 镜像 VanPickingController::approve 的反向：源是车上仓、目的是普通仓，
     * 无 freeze/unfreeze（freeze 只守仓库→车方向，反向退仓直接移库）。
     */
    public function approve(Request $request, $id)
    {
        $validated = $request->validate(['approval_comment' => 'nullable|string|max:500']);

        $order = VanReturnToWarehouse::with('items')->find($id);
        if (! $order) {
            return $this->notFound('退仓单不存在');
        }
        if ($order->status === VanReturnToWarehouse::STATUS_APPROVED) {
            return $this->success($order, '已审核，无需重复操作');
        }
        if ($order->status !== 'pending') {
            return $this->error('只有待审核的退仓单才能审核', 422);
        }

        [$adminId, $adminName] = $this->currentAdmin();

        try {
            DB::transaction(function () use ($order, $adminId, $adminName, $validated) {
                foreach ($order->items as $item) {
                    $returnQty = (int) $item->return_qty;
                    if ($returnQty <= 0) {
                        continue;
                    }
                    // 车上仓出库
                    $this->stockService->stockOut(
                        (int) $item->product_id,
                        (int) $order->vehicle_warehouse_id,
                        $returnQty,
                        (int) $order->id,
                        'VanReturnToWarehouse',
                        '车上退仓出库'
                    );
                    // 目的普通仓入库
                    $this->stockService->stockIn(
                        (int) $item->product_id,
                        (int) $order->warehouse_id,
                        $returnQty,
                        (float) $item->unit_cost,
                        (int) $order->id,
                        'VanReturnToWarehouse'
                    );
                }

                $order->update([
                    'status' => VanReturnToWarehouse::STATUS_APPROVED,
                    'approved_by' => $adminId,
                    'approver_name' => $adminName,
                    'approved_at' => now(),
                    'approval_comment' => $validated['approval_comment'] ?? null,
                ]);
                $this->writeOperationLog($order, $adminId, $adminName, 'approve', '审核通过', 'pending', VanReturnToWarehouse::STATUS_APPROVED);
            });
        } catch (StockRuleException $e) {
            return $this->error('审核失败：'.$e->getMessage().'，请检查车上库存后重试', 422);
        }

        return $this->success($order->load('items', 'vehicle', 'vehicleWarehouse', 'warehouse'), '审核通过，车上库存已退回仓库');
    }

    /** 驳回（pending → draft） */
    public function reject(Request $request, $id)
    {
        $comment = $request->input('comment', $request->input('approval_comment'));

        $order = VanReturnToWarehouse::find($id);
        if (! $order) {
            return $this->notFound('退仓单不存在');
        }
        if ($order->status !== 'pending') {
            return $this->error('只有待审核的退仓单才能驳回', 422);
        }

        $order->update(['status' => VanReturnToWarehouse::STATUS_DRAFT, 'approval_comment' => $comment]);
        [$adminId, $adminName] = $this->currentAdmin();
        $this->writeOperationLog($order, $adminId, $adminName, 'reject', '驳回', 'pending', VanReturnToWarehouse::STATUS_DRAFT);

        return $this->success($order, '已驳回，可继续修改');
    }

    /** 取消（draft/pending → cancelled） */
    public function cancel($id)
    {
        $order = VanReturnToWarehouse::find($id);
        if (! $order) {
            return $this->notFound('退仓单不存在');
        }
        if (! in_array($order->status, [VanReturnToWarehouse::STATUS_DRAFT, 'pending'], true)) {
            return $this->error('当前状态不能取消', 422);
        }

        $order->update(['status' => VanReturnToWarehouse::STATUS_CANCELLED]);

        return $this->success($order, '已取消');
    }

    private function writeOperationLog(VanReturnToWarehouse $order, ?int $adminId, string $adminName, string $action, string $actionLabel, ?string $fromStatus, ?string $toStatus, ?string $detail = null): void
    {
        DB::table('order_operation_logs')->insert([
            'order_id' => $order->id,
            'order_no' => $order->return_no,
            'order_type' => 'van_return_to_warehouse',
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
