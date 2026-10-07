<?php

namespace Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Stock\Models\StockCheck;
use Modules\Stock\Models\StockCheckItem;
use Modules\Stock\Services\StockService;

/**
 * 库存盘点（Stocktaking）
 *
 * 与同模块 StockCheckController（只读「库存核对」）不是一回事：本控制器负责盘点单的
 * 全生命周期——建单 → 录实盘 → 提交审核 → 审核通过后在事务内调库存 + 生成盘盈收入 /
 * 盘亏费用凭证 + 现金流水 + 经营历程。
 *
 * URL 前缀沿用 business/*（历史约定，见 modules/Stock/routes/admin.php 顶部注释）。
 */
class StocktakingController extends Controller
{
    use ResponseTrait;

    public function __construct(private StockService $stockService) {}

    /** 盘点单列表（分页 + 仓库/状态/日期/单号筛选） */
    public function index(Request $request)
    {
        $query = StockCheck::with('warehouse');

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', (int) $request->input('warehouse_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('start_date')) {
            $query->whereDate('check_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('check_date', '<=', $request->input('end_date'));
        }
        if ($request->filled('check_no')) {
            $query->where('check_no', 'like', '%'.trim((string) $request->input('check_no')).'%');
        }

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        return $this->paginated($paginator);
    }

    /**
     * 指定仓库的全部商品账面库存（新增盘点单时加载明细用）。
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
                's.quantity as book_qty',
                's.cost_price',
            ])
            ->map(function ($r) {
                $r->book_qty = (int) $r->book_qty;
                $r->cost_price = (float) $r->cost_price;
                $r->actual_qty = $r->book_qty; // 实盘默认=账面
                $r->diff_qty = 0;
                $r->diff_amount = 0.0;

                return $r;
            });

        return $this->success(['list' => $products, 'total' => $products->count()]);
    }

    /** 新增盘点单：items 携带 product_id + actual_qty；action=draft 存草稿 / action=submit 直接提交 */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'check_date' => 'nullable|date',
            'check_type' => 'nullable|in:full,sample,adjust',
            'remark' => 'nullable|string',
            'action' => 'nullable|in:draft,submit',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.actual_qty' => 'required|integer|min:0',
            'items.*.checker' => 'nullable|string|max:50',
        ]);

        $admin = auth('admin')->user();
        $adminId = $admin?->id;
        $adminName = $admin?->name ?? ($admin?->nickname ?? '管理员');

        $action = $validated['action'] ?? 'draft';
        $status = $action === 'submit' ? StockCheck::STATUS_PENDING : StockCheck::STATUS_IN_PROGRESS;

        return DB::transaction(function () use ($validated, $adminId, $adminName, $status, $action) {
            $warehouseId = (int) $validated['warehouse_id'];

            // 预取该仓库存与商品档案（一次查全，避免 N+1）
            $stockMap = DB::table('stocks')->where('warehouse_id', $warehouseId)->get()->keyBy('product_id');
            $productIds = collect($validated['items'])->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
            $productMap = DB::table('products')->whereIn('id', $productIds)->get()->keyBy('id');

            [$totalSkus, $profitQty, $lossQty, $profitAmount, $lossAmount] = [0, 0, 0, 0.0, 0.0];
            $itemRows = [];

            foreach ($validated['items'] as $item) {
                $productId = (int) $item['product_id'];
                $product = $productMap->get($productId);
                if (! $product) {
                    continue;
                }
                $stock = $stockMap->get($productId);
                $bookQty = $stock ? (int) $stock->quantity : 0;
                $costPrice = $stock && (float) $stock->cost_price > 0
                    ? (float) $stock->cost_price
                    : (float) ($product->cost_price ?? 0);
                $actualQty = (int) $item['actual_qty'];
                $diffQty = $actualQty - $bookQty;
                $diffAmount = round($diffQty * $costPrice, 2);

                $totalSkus++;
                if ($diffQty > 0) {
                    $profitQty += $diffQty;
                    $profitAmount += $diffAmount;
                } elseif ($diffQty < 0) {
                    $lossQty += abs($diffQty);
                    $lossAmount += abs($diffAmount);
                }

                $itemRows[] = [
                    'product_id' => $productId,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'spec' => $product->spec,
                    'unit' => $product->price_unit_small,
                    'book_qty' => $bookQty,
                    'actual_qty' => $actualQty,
                    'diff_qty' => $diffQty,
                    'cost_price' => $costPrice,
                    'diff_amount' => $diffAmount,
                    'checker' => $item['checker'] ?? $adminName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($totalSkus === 0) {
                return $this->error('没有可盘点的商品', 422);
            }

            $check = StockCheck::create([
                'check_no' => $this->generateNo('PD', 'stock_checks', 'check_no'),
                'warehouse_id' => $warehouseId,
                'check_date' => $validated['check_date'] ?? now()->toDateString(),
                'check_type' => $validated['check_type'] ?? 'full',
                'status' => $status,
                'total_skus' => $totalSkus,
                'profit_qty' => $profitQty,
                'loss_qty' => $lossQty,
                'profit_amount' => round($profitAmount, 2),
                'loss_amount' => round($lossAmount, 2),
                'remark' => $validated['remark'] ?? null,
                'created_by' => $adminId,
                'creator_name' => $adminName,
            ]);

            foreach ($itemRows as &$row) {
                $row['check_id'] = $check->id;
            }
            unset($row);
            StockCheckItem::insert($itemRows);

            $check->load('items', 'warehouse');

            return $this->created($check, $action === 'submit' ? '已提交审核' : '草稿已保存');
        });
    }

    /** 盘点单详情（含明细） */
    public function show($id)
    {
        $check = StockCheck::with(['warehouse', 'items'])->find($id);
        if (! $check) {
            return $this->notFound('盘点单不存在');
        }

        return $this->success($check);
    }

    /** 更新盘点单（仅盘点中/待盘点可改）：整体替换明细并重算合计 */
    public function update(Request $request, $id)
    {
        $check = StockCheck::find($id);
        if (! $check) {
            return $this->notFound('盘点单不存在');
        }
        if (! in_array($check->status, [StockCheck::STATUS_DRAFT, StockCheck::STATUS_IN_PROGRESS], true)) {
            return $this->error('当前状态不能修改盘点数量', 422);
        }

        $validated = $request->validate([
            'check_date' => 'nullable|date',
            'check_type' => 'nullable|in:full,sample,adjust',
            'remark' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.actual_qty' => 'required|integer|min:0',
            'items.*.checker' => 'nullable|string|max:50',
        ]);

        return DB::transaction(function () use ($check, $validated) {
            $warehouseId = (int) $check->warehouse_id;
            $stockMap = DB::table('stocks')->where('warehouse_id', $warehouseId)->get()->keyBy('product_id');
            $productIds = collect($validated['items'])->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
            $productMap = DB::table('products')->whereIn('id', $productIds)->get()->keyBy('id');

            [$totalSkus, $profitQty, $lossQty, $profitAmount, $lossAmount] = [0, 0, 0, 0.0, 0.0];
            $itemRows = [];

            foreach ($validated['items'] as $item) {
                $productId = (int) $item['product_id'];
                $product = $productMap->get($productId);
                if (! $product) {
                    continue;
                }
                $stock = $stockMap->get($productId);
                $bookQty = $stock ? (int) $stock->quantity : 0;
                $costPrice = $stock && (float) $stock->cost_price > 0 ? (float) $stock->cost_price : (float) ($product->cost_price ?? 0);
                $actualQty = (int) $item['actual_qty'];
                $diffQty = $actualQty - $bookQty;
                $diffAmount = round($diffQty * $costPrice, 2);

                $totalSkus++;
                if ($diffQty > 0) {
                    $profitQty += $diffQty;
                    $profitAmount += $diffAmount;
                } elseif ($diffQty < 0) {
                    $lossQty += abs($diffQty);
                    $lossAmount += abs($diffAmount);
                }

                $itemRows[] = [
                    'check_id' => $check->id,
                    'product_id' => $productId,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'spec' => $product->spec,
                    'unit' => $product->price_unit_small,
                    'book_qty' => $bookQty,
                    'actual_qty' => $actualQty,
                    'diff_qty' => $diffQty,
                    'cost_price' => $costPrice,
                    'diff_amount' => $diffAmount,
                    'checker' => $item['checker'] ?? $check->creator_name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $check->items()->delete();
            StockCheckItem::insert($itemRows);

            $check->update([
                'check_date' => $validated['check_date'] ?? $check->check_date?->toDateString() ?? now()->toDateString(),
                'check_type' => $validated['check_type'] ?? $check->check_type,
                'remark' => array_key_exists('remark', $validated) ? $validated['remark'] : $check->remark,
                'total_skus' => $totalSkus,
                'profit_qty' => $profitQty,
                'loss_qty' => $lossQty,
                'profit_amount' => round($profitAmount, 2),
                'loss_amount' => round($lossAmount, 2),
            ]);

            return $this->success($check->load('items', 'warehouse'), '盘点单已更新');
        });
    }

    /** 删除盘点单（仅待盘点/盘点中） */
    public function destroy($id)
    {
        $check = StockCheck::find($id);
        if (! $check) {
            return $this->notFound('盘点单不存在');
        }
        if (! in_array($check->status, [StockCheck::STATUS_DRAFT, StockCheck::STATUS_IN_PROGRESS], true)) {
            return $this->error('当前状态不能删除', 422);
        }

        $check->items()->delete();
        $check->delete();

        return $this->success(null, '已删除');
    }

    /** 提交审核（盘点中 → 待审核） */
    public function submit($id)
    {
        $check = StockCheck::with('items')->find($id);
        if (! $check) {
            return $this->notFound('盘点单不存在');
        }
        if (! in_array($check->status, [StockCheck::STATUS_DRAFT, StockCheck::STATUS_IN_PROGRESS], true)) {
            return $this->error('当前状态不能提交', 422);
        }
        if ($check->items->isEmpty()) {
            return $this->error('盘点单没有明细，请先录入实盘数量', 422);
        }

        $check->update(['status' => StockCheck::STATUS_PENDING]);

        return $this->success($check, '已提交审核');
    }

    /**
     * 审核通过（待审核 → 已审核）：事务内调库存 + 生成盘盈收入/盘亏费用凭证 + 现金流水 + 经营历程。
     * 任一步失败整体回滚，盘点单状态不变。
     */
    public function approve(Request $request, $id)
    {
        $validated = $request->validate([
            'approval_comment' => 'nullable|string|max:500',
        ]);

        $check = StockCheck::with('items')->find($id);
        if (! $check) {
            return $this->notFound('盘点单不存在');
        }
        if ($check->status === StockCheck::STATUS_APPROVED) {
            return $this->success($check, '已审核，无需重复操作');
        }
        if ($check->status !== StockCheck::STATUS_PENDING) {
            return $this->error('只有待审核的盘点单才能审核', 422);
        }

        $admin = auth('admin')->user();
        $adminId = $admin?->id;
        $adminName = $admin?->name ?? ($admin?->nickname ?? '管理员');

        return DB::transaction(function () use ($check, $validated, $adminId, $adminName) {
            // 1. 更新盘点单状态
            $check->update([
                'status' => StockCheck::STATUS_APPROVED,
                'approved_by' => $adminId,
                'approver_name' => $adminName,
                'approved_at' => now(),
                'approval_comment' => $validated['approval_comment'] ?? null,
            ]);

            // 2. 遍历差异商品调库存（盘盈 + / 盘亏 -），流水类型 check_in/check_out
            foreach ($check->items as $item) {
                $diff = (int) $item->diff_qty;
                if ($diff === 0) {
                    continue;
                }
                $this->stockService->adjust(
                    (int) $item->product_id,
                    (int) $check->warehouse_id,
                    $diff,
                    (float) $item->cost_price,
                    (int) $check->id,
                    $diff > 0 ? 'check_in' : 'check_out',
                    ($diff > 0 ? '库存盘盈-' : '库存盘亏-').$check->check_no,
                    'Stocktaking'
                );
            }

            $profitAmount = (float) $check->profit_amount;
            $lossAmount = (float) $check->loss_amount;
            $checkDate = $check->check_date?->toDateString() ?? now()->toDateString();

            // 3. 盘盈 → 其他收入单（自动审核）
            if ($profitAmount > 0.01) {
                $receiveNo = $this->generateNo('QT', 'receives', 'receive_no');
                DB::table('receives')->insert([
                    'receive_no' => $receiveNo,
                    'receive_type' => 2, // 其他收款
                    'customer_id' => null,
                    'sales_order_id' => null,
                    'amount' => $profitAmount,
                    'receive_date' => $checkDate,
                    'payment_method' => '现金',
                    'handler_id' => $adminId,
                    'remark' => '库存盘盈-'.$check->check_no,
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->writeCashFlow('receive', $profitAmount, $check->id, 'Stocktaking', $checkDate, '盘盈收入：'.$check->check_no, $adminId);
            }

            // 4. 盘亏 → 一般费用单（自动审核）
            if ($lossAmount > 0.01) {
                $expenseNo = $this->generateNo('FY', 'expenses', 'expense_no');
                DB::table('expenses')->insert([
                    'expense_no' => $expenseNo,
                    'expense_type' => '库存盘亏',
                    'amount' => $lossAmount,
                    'expense_date' => $checkDate,
                    'handler_id' => $adminId,
                    'remark' => '库存盘亏-'.$check->check_no,
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->writeCashFlow('expense', -$lossAmount, $check->id, 'Stocktaking', $checkDate, '盘亏支出：'.$check->check_no, $adminId);
            }

            // 5. 经营历程
            DB::table('order_operation_logs')->insert([
                'order_id' => $check->id,
                'order_no' => $check->check_no,
                'order_type' => 'stocktaking',
                'user_id' => $adminId,
                'user_name' => $adminName,
                'operator_id' => $adminId,
                'operator_name' => $adminName,
                'action' => 'approve',
                'action_label' => '盘点审核通过',
                'detail' => '库存盘点审核通过，盘盈¥'.number_format($profitAmount, 2).'，盘亏¥'.number_format($lossAmount, 2),
                'remark' => $validated['approval_comment'] ?? null,
                'from_status' => StockCheck::STATUS_PENDING,
                'to_status' => StockCheck::STATUS_APPROVED,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->success($check->load('items', 'warehouse'), '审核通过，库存与财务凭证已生成');
        });
    }

    /** 审核驳回（待审核 → 盘点中，可继续修改） */
    public function reject(Request $request, $id)
    {
        $validated = $request->validate(['approval_comment' => 'nullable|string|max:500']);

        $check = StockCheck::find($id);
        if (! $check) {
            return $this->notFound('盘点单不存在');
        }
        if ($check->status !== StockCheck::STATUS_PENDING) {
            return $this->error('只有待审核的盘点单才能驳回', 422);
        }

        $check->update([
            'status' => StockCheck::STATUS_IN_PROGRESS,
            'approval_comment' => $validated['approval_comment'] ?? null,
        ]);

        return $this->success($check, '已驳回，可继续盘点');
    }

    /** 取消盘点单（待盘点/盘点中 → 已取消） */
    public function cancel($id)
    {
        $check = StockCheck::find($id);
        if (! $check) {
            return $this->notFound('盘点单不存在');
        }
        if (! in_array($check->status, [StockCheck::STATUS_DRAFT, StockCheck::STATUS_IN_PROGRESS, StockCheck::STATUS_PENDING], true)) {
            return $this->error('当前状态不能取消', 422);
        }

        $check->update(['status' => StockCheck::STATUS_CANCELLED]);

        return $this->success($check, '已取消');
    }

    /**
     * 库存台账：某商品（可限定仓库）在日期范围内的逐笔变动流水 + 期初/期末结存。
     * 数据源 stocks_history。
     */
    public function ledger(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
        ]);
        $productId = (int) $request->input('product_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        // 期初：起始日之前所有变动的累计结存
        $openingQuery = DB::table('stocks_history')->where('product_id', $productId);
        if ($request->filled('warehouse_id')) {
            $openingQuery->where('warehouse_id', (int) $request->input('warehouse_id'));
        }
        if ($startDate) {
            $openingQuery->whereDate('created_at', '<', $startDate);
        }
        $opening = (float) (clone $openingQuery)->sum('change_qty');

        $rowsQuery = DB::table('stocks_history')->where('product_id', $productId);
        if ($request->filled('warehouse_id')) {
            $rowsQuery->where('warehouse_id', (int) $request->input('warehouse_id'));
        }
        if ($startDate) {
            $rowsQuery->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $rowsQuery->whereDate('created_at', '<=', $endDate);
        }
        $rows = $rowsQuery->orderBy('created_at')->orderBy('id')->get();

        // 当前成本价（台账按当前成本展示结存金额）
        $costPrice = (float) DB::table('products')->where('id', $productId)->value('cost_price');

        $typeLabels = [
            'stock_in' => '入库', 'stock_out' => '出库',
            'check_in' => '盘盈入库', 'check_out' => '盘亏出库',
            'adjust_in' => '调整增加', 'adjust_out' => '调整减少',
            'sale_freeze' => '销售冻结', 'sale_unfreeze' => '销售解冻',
        ];

        $running = $opening;
        $list = [];
        foreach ($rows as $row) {
            $running = (float) $row->after_qty; // after_qty 即该笔后结存
            $qty = (int) $row->change_qty;
            $list[] = [
                'date' => $row->created_at,
                'change_type' => $row->change_type,
                'type_label' => $typeLabels[$row->change_type] ?? $row->change_type,
                'related_type' => $row->related_type,
                'summary' => $row->remark,
                'in_qty' => $qty > 0 ? $qty : 0,
                'out_qty' => $qty < 0 ? abs($qty) : 0,
                'balance_qty' => (int) $running,
                'cost_price' => $costPrice,
                'balance_amount' => round($running * $costPrice, 2),
            ];
        }

        $closing = $running;

        return $this->success([
            'opening' => [
                'qty' => (int) $opening,
                'amount' => round($opening * $costPrice, 2),
            ],
            'list' => $list,
            'closing' => [
                'qty' => (int) $closing,
                'amount' => round($closing * $costPrice, 2),
            ],
        ]);
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
