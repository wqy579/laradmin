<?php

namespace Modules\BorrowReturn\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\BorrowReturn\Models\BorrowOrder;
use Modules\BorrowReturn\Models\BorrowReturnOrder;
use Modules\BorrowReturn\Models\BorrowReturnOrderItem;
use Modules\BorrowReturn\Models\CustomerBorrowBalance;
use Modules\Stock\Exceptions\StockRuleException;
use Modules\Stock\Models\StockAdjust;
use Modules\Stock\Models\StockAdjustItem;
use Modules\Stock\Services\StockService;

/**
 * 还货单（BorrowReturnOrder）
 *
 * 客户归还借出商品：关联未还/部分还的借货单，审核通过后完好数量回库、借货余额减少。
 * 破损数量生成报损单（stock_adjusts, type=stock_loss）。
 * 状态机：pending(待审核) → approved(已审核，库存生效) / cancelled(已取消)
 */
class BorrowReturnOrderController extends Controller
{
    use ResponseTrait;

    public function __construct(private StockService $stockService) {}

    /** 取借货单的可还明细（未还数量），用于还货单自动带出 */
    public function pendingBorrowItems(Request $request)
    {
        $request->validate(['borrow_order_id' => 'required|exists:borrow_orders,id']);
        $borrowOrderId = (int) $request->input('borrow_order_id');

        $borrow = BorrowOrder::with(['items.product', 'customer', 'warehouse'])->find($borrowOrderId);
        if (! $borrow) {
            return $this->notFound('借货单不存在');
        }
        if (! in_array($borrow->status, [BorrowOrder::STATUS_UNRETURNED, BorrowOrder::STATUS_PARTIAL])) {
            return $this->error('该借货单不可还货（仅未还/部分还可还）', 422);
        }

        $items = $borrow->items->map(function ($it) {
            $unreturned = (int) $it->borrow_qty - (int) $it->returned_qty;

            return [
                'borrow_order_item_id' => $it->id,
                'product_id' => $it->product_id,
                'product_code' => $it->product_code,
                'product_name' => $it->product_name,
                'spec' => $it->spec,
                'unit' => $it->unit,
                'unreturned_qty' => $unreturned,
                'unit_price' => (float) $it->unit_price,
            ];
        })->filter(fn ($i) => $i['unreturned_qty'] > 0)->values();

        return $this->success([
            'borrow_order' => [
                'borrow_no' => $borrow->borrow_no,
                'customer_id' => $borrow->customer_id,
                'customer_name' => $borrow->customer_name,
                'warehouse_id' => $borrow->warehouse_id,
                'warehouse_name' => $borrow->warehouse_name,
                'salesman_id' => $borrow->salesman_id,
                'salesman_name' => $borrow->salesman_name,
            ],
            'items' => $items,
        ]);
    }

    public function index(Request $request)
    {
        $query = $this->applyFilters(BorrowReturnOrder::with(['customer', 'warehouse', 'borrowOrder']), $request);

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        return $this->paginated($query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page));
    }

    public function export(Request $request)
    {
        $list = $this->applyFilters(BorrowReturnOrder::with(['customer', 'warehouse', 'borrowOrder']), $request)
            ->orderByDesc('id')->get();

        $csv = "\u{FEFF}还货单列表\n\n";
        $csv .= "还货日期,还货单号,关联借货单,客户,仓库,业务员,商品种类,还货总数,完好,破损,金额,状态\n";

        foreach ($list as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $r->return_date?->format('Y-m-d') ?? '',
                $this->csvCell($r->return_no),
                $this->csvCell($r->borrowOrder?->borrow_no ?? ''),
                $this->csvCell($r->customer_name ?? ''),
                $this->csvCell($r->warehouse_name ?? ''),
                $this->csvCell($r->salesman_name ?? ''),
                $r->total_kinds,
                $r->total_qty,
                $r->good_qty,
                $r->bad_qty,
                number_format((float) $r->total_amount, 2, '.', ''),
                $this->statusLabel($r->status)
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="borrow_return_orders.csv"',
        ]);
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('return_no')) {
            $query->where('return_no', 'like', '%'.trim((string) $request->input('return_no')).'%');
        }
        if ($request->filled('borrow_no')) {
            $query->whereHas('borrowOrder', fn ($q) => $q->where('borrow_no', 'like', '%'.trim((string) $request->input('borrow_no')).'%'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->input('customer_id'));
        }
        if ($request->filled('customer_name')) {
            $query->where('customer_name', 'like', '%'.trim((string) $request->input('customer_name')).'%');
        }
        if ($request->filled('salesman_id')) {
            $query->where('salesman_id', (int) $request->input('salesman_id'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', (int) $request->input('warehouse_id'));
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
        return BorrowReturnOrder::statusLabel($status);
    }

    public function show($id)
    {
        $order = BorrowReturnOrder::with(['items.product', 'customer', 'warehouse', 'borrowOrder'])->find($id);
        if (! $order) {
            return $this->notFound('还货单不存在');
        }

        return $this->success($order);
    }

    /** 创建还货单（待审核） */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'borrow_order_id' => 'required|exists:borrow_orders,id',
            'return_date' => 'nullable|date',
            'return_reason' => 'nullable|string|max:40',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.borrow_order_item_id' => 'required|exists:borrow_order_items,id',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.return_qty' => 'required|integer|min:1',
            'items.*.good_qty' => 'required|integer|min:0',
            'items.*.bad_qty' => 'required|integer|min:0',
            'items.*.bad_reason' => 'nullable|string|max:255',
        ]);

        [$adminId, $adminName] = $this->currentAdmin();

        return DB::transaction(function () use ($validated, $adminId, $adminName) {
            $borrowOrderId = (int) $validated['borrow_order_id'];
            $borrow = BorrowOrder::with('items')->find($borrowOrderId);
            if (! $borrow) {
                return $this->error('借货单不存在', 422);
            }

            $borrowItemMap = $borrow->items->keyBy('id');
            $productIds = collect($validated['items'])->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
            $productMap = DB::table('products')->whereIn('id', $productIds)->get()->keyBy('id');

            $itemRows = [];
            $totalKinds = 0;
            $totalQty = 0;
            $goodQty = 0;
            $badQty = 0;
            $totalAmount = 0.0;
            $sort = 0;

            foreach ($validated['items'] as $item) {
                $boItemId = (int) $item['borrow_order_item_id'];
                $boItem = $borrowItemMap->get($boItemId);
                if (! $boItem) {
                    return $this->error('借货明细不存在', 422);
                }
                $returnQty = (int) $item['return_qty'];
                $good = (int) $item['good_qty'];
                $bad = (int) $item['bad_qty'];
                if ($good + $bad !== $returnQty) {
                    return $this->error('完好数量与破损数量之和必须等于还货数量', 422);
                }
                $unreturned = (int) $boItem->borrow_qty - (int) $boItem->returned_qty;
                if ($returnQty > $unreturned) {
                    return $this->error("商品【{$boItem->product_name}】还货数量{$returnQty}超过未还数量{$unreturned}", 422);
                }
                if ($bad > 0 && empty($item['bad_reason'])) {
                    return $this->error("商品【{$boItem->product_name}】有破损，请填写破损说明", 422);
                }

                $product = $productMap->get((int) $item['product_id']);
                $unitPrice = (float) ($boItem->unit_price ?? 0);
                $amount = round($returnQty * $unitPrice, 2);

                $itemRows[] = [
                    'borrow_order_item_id' => $boItemId,
                    'product_id' => (int) $item['product_id'],
                    'product_code' => $boItem->product_code,
                    'product_name' => $boItem->product_name,
                    'spec' => $boItem->spec,
                    'unit' => $boItem->unit,
                    'unreturned_qty' => $unreturned,
                    'return_qty' => $returnQty,
                    'good_qty' => $good,
                    'bad_qty' => $bad,
                    'bad_reason' => $item['bad_reason'] ?? null,
                    'unit_price' => $unitPrice,
                    'amount' => $amount,
                    'remark' => $item['remark'] ?? null,
                    'sort' => $sort,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $sort++;
                $totalKinds++;
                $totalQty += $returnQty;
                $goodQty += $good;
                $badQty += $bad;
                $totalAmount += $amount;
            }

            $order = BorrowReturnOrder::create([
                'return_no' => $this->generateNo('HH', 'borrow_return_orders', 'return_no'),
                'borrow_order_id' => $borrowOrderId,
                'customer_id' => $borrow->customer_id,
                'customer_name' => $borrow->customer_name,
                'warehouse_id' => $borrow->warehouse_id,
                'warehouse_name' => $borrow->warehouse_name,
                'salesman_id' => $adminId,
                'salesman_name' => $adminName,
                'return_date' => $validated['return_date'] ?? now()->toDateString(),
                'return_reason' => $validated['return_reason'] ?? null,
                'total_kinds' => $totalKinds,
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
                'good_qty' => $goodQty,
                'bad_qty' => $badQty,
                'status' => BorrowReturnOrder::STATUS_PENDING,
                'created_by' => $adminId,
                'creator_name' => $adminName,
                'remark' => $validated['remark'] ?? null,
            ]);

            foreach ($itemRows as &$row) {
                $row['order_id'] = $order->id;
            }
            unset($row);
            BorrowReturnOrderItem::insert($itemRows);

            return $this->created($order->load('items', 'customer', 'warehouse', 'borrowOrder'), '还货单已创建，待审核');
        });
    }

    public function update(Request $request, $id)
    {
        $order = BorrowReturnOrder::find($id);
        if (! $order) {
            return $this->notFound('还货单不存在');
        }
        if ($order->status !== BorrowReturnOrder::STATUS_PENDING) {
            return $this->error('只有待审核的还货单才能修改', 422);
        }

        $validated = $request->validate([
            'return_date' => 'nullable|date',
            'return_reason' => 'nullable|string|max:40',
            'remark' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.borrow_order_item_id' => 'required|exists:borrow_order_items,id',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.return_qty' => 'required|integer|min:1',
            'items.*.good_qty' => 'required|integer|min:0',
            'items.*.bad_qty' => 'required|integer|min:0',
            'items.*.bad_reason' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($order, $validated) {
            $borrow = BorrowOrder::with('items')->find($order->borrow_order_id);
            $borrowItemMap = $borrow ? $borrow->items->keyBy('id') : collect();
            $productMap = DB::table('products')->whereIn('id', collect($validated['items'])->pluck('product_id')->map(fn ($v) => (int) $v)->unique())->get()->keyBy('id');

            $itemRows = [];
            $totalKinds = 0;
            $totalQty = 0;
            $goodQty = 0;
            $badQty = 0;
            $totalAmount = 0.0;
            $sort = 0;

            foreach ($validated['items'] as $item) {
                $boItem = $borrowItemMap->get((int) $item['borrow_order_item_id']);
                if (! $boItem) {
                    return $this->error('借货明细不存在', 422);
                }
                $returnQty = (int) $item['return_qty'];
                $good = (int) $item['good_qty'];
                $bad = (int) $item['bad_qty'];
                if ($good + $bad !== $returnQty) {
                    return $this->error('完好数量与破损数量之和必须等于还货数量', 422);
                }
                if ($bad > 0 && empty($item['bad_reason'])) {
                    return $this->error('有破损请填写破损说明', 422);
                }
                $unitPrice = (float) ($boItem->unit_price ?? 0);
                $amount = round($returnQty * $unitPrice, 2);

                $itemRows[] = [
                    'borrow_order_item_id' => (int) $item['borrow_order_item_id'],
                    'product_id' => (int) $item['product_id'],
                    'product_code' => $boItem->product_code,
                    'product_name' => $boItem->product_name,
                    'spec' => $boItem->spec,
                    'unit' => $boItem->unit,
                    'unreturned_qty' => (int) $boItem->borrow_qty - (int) $boItem->returned_qty,
                    'return_qty' => $returnQty,
                    'good_qty' => $good,
                    'bad_qty' => $bad,
                    'bad_reason' => $item['bad_reason'] ?? null,
                    'unit_price' => $unitPrice,
                    'amount' => $amount,
                    'remark' => $item['remark'] ?? null,
                    'sort' => $sort,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $sort++;
                $totalKinds++;
                $totalQty += $returnQty;
                $goodQty += $good;
                $badQty += $bad;
                $totalAmount += $amount;
            }

            $order->items()->delete();
            foreach ($itemRows as &$row) {
                $row['order_id'] = $order->id;
            }
            unset($row);
            BorrowReturnOrderItem::insert($itemRows);

            $order->update([
                'return_date' => $validated['return_date'] ?? $order->return_date?->toDateString() ?? now()->toDateString(),
                'return_reason' => $validated['return_reason'] ?? null,
                'total_kinds' => $totalKinds,
                'total_qty' => $totalQty,
                'total_amount' => round($totalAmount, 2),
                'good_qty' => $goodQty,
                'bad_qty' => $badQty,
                'remark' => array_key_exists('remark', $validated) ? $validated['remark'] : $order->remark,
            ]);

            return $this->success($order->load('items', 'customer', 'warehouse', 'borrowOrder'), '还货单已更新');
        });
    }

    public function destroy($id)
    {
        $order = BorrowReturnOrder::find($id);
        if (! $order) {
            return $this->notFound('还货单不存在');
        }
        if ($order->status !== BorrowReturnOrder::STATUS_PENDING) {
            return $this->error('当前状态不能删除', 422);
        }

        $order->items()->delete();
        $order->delete();

        return $this->success(null, '已删除');
    }

    /** 审核还货（pending → approved）：完好回库 + 减借货余额 + 破损生成报损单 */
    public function approve($id)
    {
        $order = BorrowReturnOrder::with('items')->find($id);
        if (! $order) {
            return $this->notFound('还货单不存在');
        }
        if ($order->status === BorrowReturnOrder::STATUS_APPROVED) {
            return $this->success($order, '已审核，无需重复操作');
        }
        if ($order->status !== BorrowReturnOrder::STATUS_PENDING) {
            return $this->error('只有待审核的还货单才能审核', 422);
        }

        [$adminId, $adminName] = $this->currentAdmin();

        try {
            DB::transaction(function () use ($order, $adminId, $adminName) {
                $whId = (int) $order->warehouse_id;
                $customerId = (int) $order->customer_id;
                $borrow = BorrowOrder::with('items')->find($order->borrow_order_id);
                $borrowItemMap = $borrow ? $borrow->items->keyBy('id') : collect();

                $totalReturnedQty = 0;
                $totalReturnedAmount = 0.0;
                $badItems = [];

                foreach ($order->items as $item) {
                    $returnQty = (int) $item->return_qty;
                    if ($returnQty <= 0) {
                        continue;
                    }
                    // 完好数量回库
                    if ((int) $item->good_qty > 0) {
                        $this->stockService->stockIn(
                            (int) $item->product_id,
                            $whId,
                            (int) $item->good_qty,
                            null,
                            (int) $order->id,
                            'BorrowReturnOrder'
                        );
                    }
                    // 减客户借货余额（按总还货数量）
                    $this->decreaseBorrowBalance($customerId, (int) $item->product_id, $returnQty);

                    // 回写借货明细已还数量
                    $boItem = $borrowItemMap->get((int) $item->borrow_order_item_id);
                    if ($boItem) {
                        $boItem->increment('returned_qty', $returnQty);
                    }

                    $totalReturnedQty += $returnQty;
                    $totalReturnedAmount += (float) $item->amount;

                    if ((int) $item->bad_qty > 0) {
                        $badItems[] = $item;
                    }
                }

                // 回写借货单汇总与状态
                if ($borrow) {
                    $borrow->increment('returned_qty', $totalReturnedQty);
                    $borrow->increment('returned_amount', round($totalReturnedAmount, 2));
                    $this->recomputeBorrowStatus($borrow);
                }

                // 破损生成报损单
                if (! empty($badItems)) {
                    $this->createLossOrder($order, $badItems, $adminId, $adminName);
                }

                $order->update(['status' => BorrowReturnOrder::STATUS_APPROVED, 'approved_at' => now()]);
                $this->writeOperationLog($order, $adminId, $adminName, 'approve', '还货审核通过', BorrowReturnOrder::STATUS_PENDING, BorrowReturnOrder::STATUS_APPROVED, '还入数量'.$totalReturnedQty.'，完好'.$order->good_qty.'，破损'.$order->bad_qty);
            });
        } catch (StockRuleException $e) {
            return $this->error('审核还货失败：'.$e->getMessage(), 422);
        }

        return $this->success($order->load('items', 'customer', 'warehouse', 'borrowOrder'), '还货已审核，库存已更新');
    }

    public function cancel($id, Request $request)
    {
        $order = BorrowReturnOrder::find($id);
        if (! $order) {
            return $this->notFound('还货单不存在');
        }
        if ($order->status !== BorrowReturnOrder::STATUS_PENDING) {
            return $this->error('只有待审核的还货单才能取消', 422);
        }

        $request->validate(['cancel_reason' => 'required|string|max:500']);

        $order->update([
            'status' => BorrowReturnOrder::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => $request->input('cancel_reason'),
        ]);

        return $this->success($order, '已取消');
    }

    private function recomputeBorrowStatus(BorrowOrder $borrow): void
    {
        $total = (int) $borrow->total_qty;
        $returned = (int) $borrow->returned_qty;
        if ($returned <= 0) {
            $status = BorrowOrder::STATUS_UNRETURNED;
        } elseif ($returned >= $total) {
            $status = BorrowOrder::STATUS_CLEARED;
        } else {
            $status = BorrowOrder::STATUS_PARTIAL;
        }
        $borrow->update(['status' => $status]);
    }

    private function createLossOrder(BorrowReturnOrder $order, array $badItems, ?int $adminId, string $adminName): void
    {
        $adjust = StockAdjust::create([
            'adjust_no' => $this->generateNo('BS', 'stock_adjusts', 'adjust_no'),
            'warehouse_id' => $order->warehouse_id,
            'adjust_date' => $order->return_date?->toDateString() ?? now()->toDateString(),
            'adjust_type' => StockAdjust::TYPE_STOCK_LOSS,
            'status' => StockAdjust::STATUS_APPROVED,
            'total_qty' => (int) collect($badItems)->sum('bad_qty'),
            'total_amount' => 0,
            'reason' => '还货破损报损-'.$order->return_no,
            'created_by' => $adminId,
            'creator_name' => $adminName,
            'approved_by' => $adminId,
            'approver_name' => $adminName,
            'approved_at' => now(),
        ]);

        $adjustItems = [];
        foreach ($badItems as $item) {
            $beforeQty = (int) DB::table('stocks')->where('warehouse_id', $order->warehouse_id)
                ->where('product_id', $item->product_id)->value('quantity');
            $badQty = (int) $item->bad_qty;
            $adjustItems[] = [
                'adjust_id' => $adjust->id,
                'product_id' => $item->product_id,
                'product_code' => $item->product_code,
                'product_name' => $item->product_name,
                'spec' => $item->spec,
                'unit' => $item->unit,
                'before_qty' => $beforeQty,
                'adjust_qty' => -$badQty,
                'after_qty' => $beforeQty - $badQty,
                'unit_cost' => 0,
                'total_cost' => 0,
                'remark' => $item->bad_reason,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        StockAdjustItem::insert($adjustItems);
    }

    private function decreaseBorrowBalance(int $customerId, int $productId, int $qty): void
    {
        $balance = CustomerBorrowBalance::where('customer_id', $customerId)
            ->where('product_id', $productId)->lockForUpdate()->first();
        if (! $balance) {
            return;
        }
        $newQty = (int) $balance->qty - $qty;
        if ($newQty <= 0) {
            $balance->delete();
        } else {
            $balance->update(['qty' => $newQty, 'updated_at' => now()]);
        }
    }

    private function writeOperationLog(BorrowReturnOrder $order, ?int $adminId, string $adminName, string $action, string $actionLabel, ?string $fromStatus, ?string $toStatus, ?string $detail = null): void
    {
        DB::table('order_operation_logs')->insert([
            'order_id' => $order->id,
            'order_no' => $order->return_no,
            'order_type' => 'borrow_return_order',
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
