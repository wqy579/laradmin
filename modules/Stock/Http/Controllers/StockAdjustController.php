<?php

namespace Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Stock\Models\StockAdjust;
use Modules\Stock\Models\StockAdjustItem;
use Modules\Stock\Services\StockService;

/**
 * 库存调整单（StockAdjust）
 *
 * 与库存盘点（stock_checks / StocktakingController）的区别：
 *   - 盘点：账面→录实盘→自动算差异
 *   - 调整：直接录入调整数量和原因，流程更轻量
 *
 * 共用 StockService::adjust() 调库存，change_type = 'adjust_in' / 'adjust_out'。
 * 经营历程 type_key = 'stock_adjust'（在 BusinessHistoryController 新增）。
 */
class StockAdjustController extends Controller
{
    use ResponseTrait;

    public function __construct(private StockService $stockService) {}

    /**
     * 指定仓库的全部商品账面库存（新增调整单时加载明细用）。
     * 取该仓 stocks 行（含 0 库存商品），附成本价。
     */
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
                's.product_id',
                'p.code as product_code',
                'p.name as product_name',
                'p.spec',
                'p.price_unit_small as unit',
                's.quantity as stock_qty',
                's.cost_price',
            ])
            ->map(function ($r) {
                $r->stock_qty = (int) $r->stock_qty;
                $r->cost_price = (float) $r->cost_price;

                return $r;
            });

        return $this->success(['list' => $products, 'total' => $products->count()]);
    }

    /** 列表（分页 + 仓库/状态/日期/单号筛选） */
    public function index(Request $request)
    {
        $query = StockAdjust::with('warehouse');

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', (int) $request->input('warehouse_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('adjust_type')) {
            $query->where('adjust_type', $request->input('adjust_type'));
        }
        if ($request->filled('start_date')) {
            $query->whereDate('adjust_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('adjust_date', '<=', $request->input('end_date'));
        }
        if ($request->filled('adjust_no')) {
            $query->where('adjust_no', 'like', '%'.trim((string) $request->input('adjust_no')).'%');
        }

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        return $this->paginated($paginator);
    }

    /** 详情（含明细） */
    public function show($id)
    {
        $adjust = StockAdjust::with(['warehouse', 'items.product'])->find($id);
        if (! $adjust) {
            return $this->notFound('调整单不存在');
        }

        return $this->success($adjust);
    }

    /** 创建草稿 */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'adjust_date' => 'nullable|date',
            'adjust_type' => 'nullable|in:stock_loss,stock_gain,other',
            'reason' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.adjust_qty' => 'required|numeric',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.remark' => 'nullable|string|max:255',
        ]);

        $admin = auth('admin')->user();
        $adminId = $admin?->id;
        $adminName = $admin?->real_name ?? $admin?->username ?? '管理员';

        return DB::transaction(function () use ($validated, $adminId, $adminName) {
            $warehouseId = (int) $validated['warehouse_id'];

            // 预取该仓库存 + 商品档案（一次查全，避免 N+1）
            $stockMap = DB::table('stocks')->where('warehouse_id', $warehouseId)->get()->keyBy('product_id');
            $productIds = collect($validated['items'])->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
            $productMap = DB::table('products')->whereIn('id', $productIds)->get()->keyBy('id');

            $totalQty = 0.0;
            $totalAmount = 0.0;
            $itemRows = [];

            foreach ($validated['items'] as $item) {
                $productId = (int) $item['product_id'];
                $product = $productMap->get($productId);
                if (! $product) {
                    continue;
                }
                $stock = $stockMap->get($productId);
                $beforeQty = (float) ($stock?->quantity ?? 0);
                $adjustQty = (float) $item['adjust_qty'];
                $afterQty = $beforeQty + $adjustQty;
                $unitCost = (float) ($item['unit_cost'] ?? ($stock?->cost_price ?? $product->cost_price ?? 0));
                $totalCost = round(abs($adjustQty) * $unitCost, 2);

                $totalQty += $adjustQty;
                $totalAmount += $totalCost;

                $itemRows[] = [
                    'product_id' => $productId,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'spec' => $product->spec,
                    'unit' => $product->price_unit_small,
                    'before_qty' => $beforeQty,
                    'adjust_qty' => $adjustQty,
                    'after_qty' => $afterQty,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'remark' => $item['remark'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (empty($itemRows)) {
                return $this->error('没有有效的商品明细', 422);
            }

            $adjust = StockAdjust::create([
                'adjust_no' => $this->generateNo('TZ', 'stock_adjusts', 'adjust_no'),
                'warehouse_id' => $warehouseId,
                'adjust_date' => $validated['adjust_date'] ?? now()->toDateString(),
                'adjust_type' => $validated['adjust_type'] ?? 'other',
                'status' => StockAdjust::STATUS_DRAFT,
                'total_qty' => round($totalQty, 2),
                'total_amount' => round($totalAmount, 2),
                'reason' => $validated['reason'] ?? null,
                'created_by' => $adminId,
                'creator_name' => $adminName,
            ]);

            foreach ($itemRows as &$row) {
                $row['adjust_id'] = $adjust->id;
            }
            unset($row);
            StockAdjustItem::insert($itemRows);

            return $this->created($adjust->load('items', 'warehouse'), '草稿已保存');
        });
    }

    /** 更新草稿（仅草稿状态可改） */
    public function update(Request $request, $id)
    {
        $adjust = StockAdjust::find($id);
        if (! $adjust) {
            return $this->notFound('调整单不存在');
        }
        if ($adjust->status !== StockAdjust::STATUS_DRAFT) {
            return $this->error('当前状态不能修改', 422);
        }

        $validated = $request->validate([
            'adjust_date' => 'nullable|date',
            'adjust_type' => 'nullable|in:stock_loss,stock_gain,other',
            'reason' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.adjust_qty' => 'required|numeric',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.remark' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($adjust, $validated) {
            $warehouseId = (int) $adjust->warehouse_id;
            $stockMap = DB::table('stocks')->where('warehouse_id', $warehouseId)->get()->keyBy('product_id');
            $productIds = collect($validated['items'])->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
            $productMap = DB::table('products')->whereIn('id', $productIds)->get()->keyBy('id');

            $totalQty = 0.0;
            $totalAmount = 0.0;
            $itemRows = [];

            foreach ($validated['items'] as $item) {
                $productId = (int) $item['product_id'];
                $product = $productMap->get($productId);
                if (! $product) {
                    continue;
                }
                $stock = $stockMap->get($productId);
                $beforeQty = (float) ($stock?->quantity ?? 0);
                $adjustQty = (float) $item['adjust_qty'];
                $afterQty = $beforeQty + $adjustQty;
                $unitCost = (float) ($item['unit_cost'] ?? ($stock?->cost_price ?? $product->cost_price ?? 0));
                $totalCost = round(abs($adjustQty) * $unitCost, 2);

                $totalQty += $adjustQty;
                $totalAmount += $totalCost;

                $itemRows[] = [
                    'adjust_id' => $adjust->id,
                    'product_id' => $productId,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'spec' => $product->spec,
                    'unit' => $product->price_unit_small,
                    'before_qty' => $beforeQty,
                    'adjust_qty' => $adjustQty,
                    'after_qty' => $afterQty,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'remark' => $item['remark'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (empty($itemRows)) {
                return $this->error('没有有效的商品明细', 422);
            }

            $adjust->items()->delete();
            StockAdjustItem::insert($itemRows);

            $adjust->update([
                'adjust_date' => $validated['adjust_date'] ?? $adjust->adjust_date?->toDateString() ?? now()->toDateString(),
                'adjust_type' => $validated['adjust_type'] ?? $adjust->adjust_type,
                'reason' => array_key_exists('reason', $validated) ? $validated['reason'] : $adjust->reason,
                'total_qty' => round($totalQty, 2),
                'total_amount' => round($totalAmount, 2),
            ]);

            return $this->success($adjust->load('items', 'warehouse'), '草稿已更新');
        });
    }

    /** 删除草稿（仅草稿状态） */
    public function destroy($id)
    {
        $adjust = StockAdjust::find($id);
        if (! $adjust) {
            return $this->notFound('调整单不存在');
        }
        if ($adjust->status !== StockAdjust::STATUS_DRAFT) {
            return $this->error('当前状态不能删除', 422);
        }

        $adjust->items()->delete();
        $adjust->delete();

        return $this->success(null, '已删除');
    }

    /** 提交审核（draft → pending） */
    public function submit($id)
    {
        $adjust = StockAdjust::with('items')->find($id);
        if (! $adjust) {
            return $this->notFound('调整单不存在');
        }
        if ($adjust->status !== StockAdjust::STATUS_DRAFT) {
            return $this->error('当前状态不能提交', 422);
        }
        if ($adjust->items->isEmpty()) {
            return $this->error('调整单没有明细，请先添加商品', 422);
        }
        if (empty(trim((string) $adjust->reason))) {
            return $this->error('调整原因必填', 422);
        }

        $adjust->update(['status' => StockAdjust::STATUS_PENDING]);

        return $this->success($adjust, '已提交审核');
    }

    /**
     * 审核通过（pending → approved）：事务内调库存 + 写现金流水（损耗/溢余）+ 经营历程。
     */
    public function approve(Request $request, $id)
    {
        $validated = $request->validate(['approval_comment' => 'nullable|string|max:500']);

        $adjust = StockAdjust::with('items')->find($id);
        if (! $adjust) {
            return $this->notFound('调整单不存在');
        }
        if ($adjust->status === StockAdjust::STATUS_APPROVED) {
            return $this->success($adjust, '已审核，无需重复操作');
        }
        if ($adjust->status !== StockAdjust::STATUS_PENDING) {
            return $this->error('只有待审核的调整单才能审核', 422);
        }

        $admin = auth('admin')->user();
        $adminId = $admin?->id;
        $adminName = $admin?->real_name ?? $admin?->username ?? '管理员';

        return DB::transaction(function () use ($adjust, $validated, $adminId, $adminName) {
            // 1. 更新状态
            $adjust->update([
                'status' => StockAdjust::STATUS_APPROVED,
                'approved_by' => $adminId,
                'approver_name' => $adminName,
                'approved_at' => now(),
                'approval_comment' => $validated['approval_comment'] ?? null,
            ]);

            // 2. 遍历明细调库存
            $lossAmount = 0.0;
            $gainAmount = 0.0;
            $adjustDate = $adjust->adjust_date?->toDateString() ?? now()->toDateString();

            foreach ($adjust->items as $item) {
                $adjustQty = (float) $item->adjust_qty;
                if ($adjustQty == 0) {
                    continue;
                }
                $changeType = $adjustQty > 0 ? 'adjust_in' : 'adjust_out';
                $this->stockService->adjust(
                    (int) $item->product_id,
                    (int) $adjust->warehouse_id,
                    (int) round($adjustQty),
                    (float) $item->unit_cost,
                    (int) $adjust->id,
                    $changeType,
                    ($adjustQty > 0 ? '库存调整入库-' : '库存调整出库-').$adjust->adjust_no
                );

                $totalCost = (float) $item->total_cost;
                if ($adjustQty < 0) {
                    $lossAmount += $totalCost;
                } else {
                    $gainAmount += $totalCost;
                }
            }

            // 3. 库存损耗 → 费用单（自动审核）
            if ($lossAmount > 0.01) {
                $expenseNo = $this->generateNo('FY', 'expenses', 'expense_no');
                DB::table('expenses')->insert([
                    'expense_no' => $expenseNo,
                    'expense_type' => '库存损耗',
                    'amount' => $lossAmount,
                    'expense_date' => $adjustDate,
                    'handler_id' => $adminId,
                    'remark' => '库存调整损耗-'.$adjust->adjust_no,
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->writeCashFlow('expense', -$lossAmount, $adjust->id, 'StockAdjust', $adjustDate, '损耗支出：'.$adjust->adjust_no, $adminId);
            }

            // 4. 库存溢余 → 收款单（自动审核）
            if ($gainAmount > 0.01) {
                $receiveNo = $this->generateNo('QT', 'receives', 'receive_no');
                DB::table('receives')->insert([
                    'receive_no' => $receiveNo,
                    'receive_type' => 2, // 其他收款
                    'customer_id' => null,
                    'sales_order_id' => null,
                    'amount' => $gainAmount,
                    'receive_date' => $adjustDate,
                    'payment_method' => '现金',
                    'handler_id' => $adminId,
                    'remark' => '库存调整溢余-'.$adjust->adjust_no,
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->writeCashFlow('receive', $gainAmount, $adjust->id, 'StockAdjust', $adjustDate, '溢余收入：'.$adjust->adjust_no, $adminId);
            }

            // 5. 经营历程（写入 order_operation_logs）
            DB::table('order_operation_logs')->insert([
                'order_id' => $adjust->id,
                'order_no' => $adjust->adjust_no,
                'order_type' => 'stock_adjust',
                'user_id' => $adminId,
                'user_name' => $adminName,
                'operator_id' => $adminId,
                'operator_name' => $adminName,
                'action' => 'approve',
                'action_label' => '调整审核通过',
                'detail' => '库存调整审核通过，溢余¥'.number_format($gainAmount, 2).'，损耗¥'.number_format($lossAmount, 2),
                'remark' => $validated['approval_comment'] ?? null,
                'from_status' => StockAdjust::STATUS_PENDING,
                'to_status' => StockAdjust::STATUS_APPROVED,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->success($adjust->load('items', 'warehouse'), '审核通过，库存与财务凭证已生成');
        });
    }

    /** 审核驳回（pending → draft，可继续修改） */
    public function reject(Request $request, $id)
    {
        $validated = $request->validate(['approval_comment' => 'nullable|string|max:500']);

        $adjust = StockAdjust::find($id);
        if (! $adjust) {
            return $this->notFound('调整单不存在');
        }
        if ($adjust->status !== StockAdjust::STATUS_PENDING) {
            return $this->error('只有待审核的调整单才能驳回', 422);
        }

        $adjust->update(['status' => StockAdjust::STATUS_DRAFT, 'approval_comment' => $validated['approval_comment'] ?? null]);

        return $this->success($adjust, '已驳回，可继续修改');
    }

    /** 取消（draft/pending → cancelled） */
    public function cancel($id)
    {
        $adjust = StockAdjust::find($id);
        if (! $adjust) {
            return $this->notFound('调整单不存在');
        }
        if (! in_array($adjust->status, [StockAdjust::STATUS_DRAFT, StockAdjust::STATUS_PENDING], true)) {
            return $this->error('当前状态不能取消', 422);
        }

        $adjust->update(['status' => StockAdjust::STATUS_CANCELLED]);

        return $this->success($adjust, '已取消');
    }

    /** 写一条现金流水（amount 正为收入、负为支出） */
    private function writeCashFlow(string $type, float $amount, ?int $relatedId, ?string $relatedType, string $date, string $remark, ?int $adminId): void
    {
        DB::table('cash_flows')->insert([
            'flow_no' => 'CF'.date('YmdHis').strtoupper(Str::random(4)),
            'flow_type' => $type,
            'customer_id' => null,
            'supplier_id' => null,
            'related_id' => $relatedId,
            'related_type' => $relatedType,
            'flow_date' => $date,
            'amount' => $amount,
            'payment_method' => '现金',
            'remark' => $remark,
            'created_by' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** 单号：前缀+Ymd+6位序号 */
    private function generateNo(string $prefix, string $table, string $column): string
    {
        $like = $prefix.date('Ymd').'%';
        $last = DB::table($table)->where($column, 'like', $like)->orderByDesc($column)->value($column);
        $seq = $last ? intval(substr($last, -6)) + 1 : 1;

        return $prefix.date('Ymd').str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
