<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Split;
use Modules\Order\Services\AssemblyService;
use Modules\Stock\Exceptions\StockRuleException;

class SplitController extends Controller
{
    use ResponseTrait;

    public function __construct(private AssemblyService $assemblyService) {}

    public function index(Request $request)
    {
        $query = Split::with(['warehouse', 'parentProduct', 'creator', 'approver'])->withCount('items');
        if ($request->filled('split_no')) {
            $query->where('split_no', 'like', "%{$request->split_no}%");
        }
        if ($request->filled('parent_product_name')) {
            $query->where('parent_product_name', 'like', "%{$request->parent_product_name}%");
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('created_by')) {
            $query->where('created_by', $request->created_by);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('start_date')) {
            $query->where('split_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('split_date', '<=', $request->end_date);
        }
        $list = $query->orderByDesc('id')->paginate(
            $request->integer('page_size', 20), ['*'], 'page', $request->integer('page', 1)
        );

        // 统一字段命名：对前端暴露 unit_usage 与 assembly 对称
        $items = $list->map(function ($item) {
            return [
                ...$item->toArray(),
                'items' => $item->items->map(function ($i) {
                    return [
                        'product_id' => $i->product_id,
                        'product_name' => $i->product_name,
                        'split_qty' => (int) $i->split_qty,
                        'split_total' => (int) $i->split_total,
                        'unit_usage' => (int) $i->split_qty,
                        'total_usage' => (int) $i->split_total,
                        'unit_cost' => (float) $i->unit_cost,
                        'total_cost' => (float) $i->total_cost,
                    ];
                })->values(),
            ];
        });

        return $this->paginated([
            'data' => $items,
            'total' => $list->total(),
            'page' => $list->currentPage(),
            'page_size' => $list->perPage(),
        ]);
    }

    /** 批量审核：勾选多张待审核拆分单批量通过，全部在一个事务内执行库存联动 */
    public function batchApprove(Request $request)
    {
        $request->validate(['ids' => 'required|array|min:1']);
        $admin = auth('admin')->user();
        $adminId = $admin?->id;
        $adminName = $admin?->real_name ?? $admin?->username ?? ($admin?->name ?? '管理员');

        try {
            $result = DB::transaction(function () use ($request, $adminId, $adminName) {
                $approved = 0;
                $skipped = [];
                foreach ($request->ids as $id) {
                    $split = Split::with(['items.product'])->find($id);
                    if (! $split) {
                        continue;
                    }
                    if ($split->status === 'approved') {
                        $skipped[] = ['id' => $id, 'reason' => '已审核'];
                        continue;
                    }
                    if ($split->status !== 'pending') {
                        $skipped[] = ['id' => $id, 'reason' => '非待审核'];
                        continue;
                    }
                    $this->assemblyService->approveSplit($split, $adminId, $adminName, '批量审核通过');
                    $approved++;
                }

                return ['approved' => $approved, 'skipped' => $skipped];
            });
        } catch (StockRuleException $e) {
            return $this->error('批量审核失败：'.$e->getMessage().'，已全部回滚', 422);
        }

        return $this->success($result, "批量审核完成：成功 {$result['approved']} 张，跳过 ".count($result['skipped']).' 张');
    }

    public function show($id)
    {
        $split = Split::with(['warehouse', 'parentProduct', 'creator', 'approver', 'items.product'])
            ->find($id);
        if ($split === null) {
            return $this->notFound();
        }

        // 统一字段命名：对前端暴露 unit_usage 与 assembly 对称
        $items = $split->items->map(function ($item) {
            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_code' => $item->product_code,
                'product_name' => $item->product_name,
                'spec' => $item->spec,
                'unit' => $item->unit,
                'split_qty' => (int) $item->split_qty,
                'split_total' => (int) $item->split_total,
                'unit_usage' => (int) $item->split_qty,  // 兼容字段
                'total_usage' => (int) $item->split_total, // 兼容字段
                'unit_cost' => (float) $item->unit_cost,
                'total_cost' => (float) $item->total_cost,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'code' => $item->product->code,
                    'spec' => $item->product->spec,
                    'price_unit_small' => $item->product->price_unit_small,
                ] : null,
            ];
        });

        return $this->success([
            ...$split->toArray(),
            'items' => $items,
        ]);
    }

    /** 取父件的拆分 BOM 模板 */
    public function bomByProduct(Request $request)
    {
        $request->validate(['product_id' => 'required|exists:products,id']);
        $bom = DB::table('product_bom as b')
            ->leftJoin('product_bom_items as bi', 'bi.bom_id', '=', 'b.id')
            ->leftJoin('products as p', 'p.id', '=', 'bi.product_id')
            ->where('b.product_id', $request->product_id)
            ->where('b.type', 'split')
            ->select(
                'b.id as bom_id', 'b.name as bom_name',
                'bi.id as item_id', 'bi.product_id', 'p.code as product_code',
                'p.name as product_name', 'p.spec', 'p.price_unit_small as unit',
                'bi.unit_usage as split_qty', 'bi.unit_cost'
            )
            ->get();

        if ($bom->isEmpty() || ! $bom->first()->item_id) {
            return $this->success(['bom_id' => null, 'name' => null, 'items' => []]);
        }

        return $this->success([
            'bom_id' => $bom->first()->bom_id,
            'name' => $bom->first()->bom_name,
            'items' => $bom->map(fn ($r) => [
                'product_id' => $r->product_id,
                'product_code' => $r->product_code ?? '',
                'product_name' => $r->product_name ?? '',
                'spec' => $r->spec ?? '',
                'unit' => $r->unit ?? '',
                'split_qty' => (int) $r->split_qty,
                'unit_cost' => (float) $r->unit_cost,
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'parent_product_id' => 'required|exists:products,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'quantity' => 'required|integer|min:1',
            'split_date' => 'required|date',
            'salesman_id' => 'nullable|integer',
            'remark' => 'nullable|string',
            'submit_for_approval' => 'nullable|boolean',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.split_qty' => 'required|integer|min:1',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        $adminId = auth('admin')?->id();
        $parent = DB::table('products')->where('id', $validated['parent_product_id'])->first();
        $quantity = (int) $validated['quantity'];

        $items = [];
        foreach ($validated['items'] as $item) {
            if ($item['product_id'] == $validated['parent_product_id']) {
                return $this->error('子件不能与被拆商品相同', 422);
            }
            $p = DB::table('products')->where('id', $item['product_id'])->first();
            $splitTotal = (int) $item['split_qty'] * $quantity;
            $items[] = [
                'product_id' => $item['product_id'],
                'product_code' => $p?->code ?? '',
                'product_name' => $p?->name ?? '',
                'spec' => $p?->spec ?? '',
                'unit' => $p?->price_unit_small ?? '',
                'split_qty' => (int) $item['split_qty'],
                'split_total' => $splitTotal,
                'unit_cost' => (float) ($item['unit_cost'] ?? 0),
                'total_cost' => 0,
            ];
        }

        return DB::transaction(function () use ($validated, $parent, $quantity, $items, $adminId) {
            $status = ! empty($validated['submit_for_approval']) ? 'pending' : 'draft';
            $split = Split::create([
                'split_no' => $this->generateNo(),
                'parent_product_id' => $validated['parent_product_id'],
                'parent_product_name' => $parent?->name ?? '',
                'parent_product_code' => $parent?->code ?? '',
                'warehouse_id' => $validated['warehouse_id'],
                'quantity' => $quantity,
                'total_cost' => 0,
                'status' => $status,
                'salesman_id' => $validated['salesman_id'] ?? $adminId,
                'split_date' => $validated['split_date'],
                'remark' => $validated['remark'] ?? null,
                'created_by' => $adminId,
            ]);
            foreach ($items as $item) {
                $item['split_order_id'] = $split->id;
                DB::table('split_order_items')->insert(array_merge($item, [
                    'created_at' => now(), 'updated_at' => now(),
                ]));
            }

            return $this->created($split->fresh(['items.product']), $status === 'pending' ? '已提交审核' : '已保存草稿');
        });
    }

    public function update(Request $request, $id)
    {
        $split = Split::find($id);
        if ($split === null) {
            return $this->notFound();
        }
        if ($split->status !== 'draft') {
            return $this->error('只有草稿状态可以编辑', 422);
        }
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'quantity' => 'required|integer|min:1',
            'split_date' => 'required|date',
            'salesman_id' => 'nullable|integer',
            'remark' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.split_qty' => 'required|integer|min:1',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        $quantity = (int) $validated['quantity'];
        $itemsData = [];
        foreach ($validated['items'] as $item) {
            $p = DB::table('products')->where('id', $item['product_id'])->first();
            $itemsData[] = [
                'product_id' => $item['product_id'],
                'product_code' => $p?->code ?? '',
                'product_name' => $p?->name ?? '',
                'spec' => $p?->spec ?? '',
                'unit' => $p?->price_unit_small ?? '',
                'split_qty' => (int) $item['split_qty'],
                'split_total' => (int) $item['split_qty'] * $quantity,
                'unit_cost' => (float) ($item['unit_cost'] ?? 0),
                'total_cost' => 0,
            ];
        }

        return DB::transaction(function () use ($split, $validated, $quantity, $itemsData) {
            $split->update([
                'warehouse_id' => $validated['warehouse_id'],
                'quantity' => $quantity,
                'split_date' => $validated['split_date'],
                'salesman_id' => $validated['salesman_id'] ?? null,
                'remark' => $validated['remark'] ?? null,
            ]);
            DB::table('split_order_items')->where('split_order_id', $split->id)->delete();
            foreach ($itemsData as $item) {
                $item['split_order_id'] = $split->id;
                DB::table('split_order_items')->insert(array_merge($item, [
                    'created_at' => now(), 'updated_at' => now(),
                ]));
            }

            return $this->success($split->fresh(['items.product']), '更新成功');
        });
    }

    public function submit($id)
    {
        $split = Split::find($id);
        if ($split === null) {
            return $this->notFound();
        }
        if ($split->status !== 'draft') {
            return $this->error('只有草稿状态可以提交审核', 422);
        }
        $split->status = 'pending';
        $split->save();

        return $this->success($split, '已提交审核');
    }

    public function approve(Request $request, $id)
    {
        $split = Split::with(['items.product'])->find($id);
        if ($split === null) {
            return $this->notFound();
        }
        if ($split->status === 'approved') {
            return $this->success($split, '已审核，无需重复操作');
        }
        if ($split->status !== 'pending') {
            return $this->error('只有待审核状态可以审核', 422);
        }
        $validated = $request->validate(['approval_comment' => 'nullable|string|max:500']);
        $admin = auth('admin')->user();
        $adminId = $admin?->id;
        $adminName = $admin?->real_name ?? $admin?->username ?? ($admin?->name ?? '管理员');

        try {
            $result = $this->assemblyService->approveSplit(
                $split, $adminId, $adminName, $validated['approval_comment'] ?? null
            );
            // Service 返回数组 [split, items]，手动拼接响应
            $split = (object) array_merge((array) $result['split'], [
                'items' => $result['items'],
            ]);
        } catch (StockRuleException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('审核失败：'.$e->getMessage(), 422);
        }

        return $this->success($split, '拆分成功，已完成父件出库和子件入库，分摊总成本¥'.number_format((float) $split->total_cost, 2));
    }

    public function reject(Request $request, $id)
    {
        $split = Split::find($id);
        if ($split === null) {
            return $this->notFound();
        }
        if ($split->status !== 'pending') {
            return $this->error('只有待审核状态可以驳回', 422);
        }
        // 兼容前端传 comment 或 approval_comment 两种参数名
        $comment = $request->input('comment', $request->input('approval_comment'));
        DB::table('split_orders')
            ->where('id', $id)
            ->update([
                'status' => 'draft',
                'approval_comment' => $comment,
                'updated_at' => now(),
            ]);
        // 返回最新数据
        $split = DB::table('split_orders')->where('id', $id)->first();

        return $this->success($split, '已驳回，可继续编辑');
    }

    public function cancel($id)
    {
        $split = Split::find($id);
        if ($split === null) {
            return $this->notFound();
        }
        if ($split->status === 'approved') {
            return $this->error('已审核的拆分单不能取消', 422);
        }
        $split->status = 'cancelled';
        $split->save();

        return $this->success($split, '已取消');
    }

    public function destroy($id)
    {
        $split = Split::find($id);
        if ($split === null) {
            return $this->notFound();
        }
        if ($split->status !== 'draft') {
            return $this->error('只有草稿状态可以删除', 422);
        }
        DB::table('split_order_items')->where('split_order_id', $split->id)->delete();
        $split->delete();

        return $this->success(null, '删除成功');
    }

    public function export(Request $request)
    {
        $request->validate([
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);
        $query = Split::with(['warehouse', 'creator', 'approver']);
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('start_date')) {
            $query->where('split_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('split_date', '<=', $request->end_date);
        }
        $list = $query->orderByDesc('id')->get();

        $csv = "\u{FEFF}";
        $csv .= "商品拆分单列表\n\n";
        $csv .= "拆分单号,被拆商品,拆分数量,子件种类,分摊总成本,仓库,拆分日期,状态,制单人,审核人\n";
        foreach ($list as $r) {
            $csv .= sprintf(
                "%s,%s,%d,%d,%s,%s,%s,%s,%s,%s\n",
                $r->split_no,
                $r->parent_product_name ?? '',
                $r->quantity,
                $r->items?->count() ?? 0,
                $r->total_cost,
                $r->warehouse?->name ?? '',
                $r->split_date?->format('Y-m-d') ?? '',
                $this->statusLabel($r->status),
                $r->creator?->real_name ?? $r->creator?->name ?? '-',
                $r->approver?->real_name ?? $r->approver?->name ?? '-'
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="split_orders.csv"',
        ]);
    }

    private function generateNo(): string
    {
        $prefix = 'CF'.date('Ymd');
        $last = Split::where('split_no', 'like', $prefix.'%')
            ->orderByDesc('split_no')
            ->value('split_no');
        $seq = $last ? intval(substr($last, -6)) + 1 : 1;

        return $prefix.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => '草稿',
            'pending' => '待审核',
            'approved' => '已审核',
            'cancelled' => '已取消',
            default => $status,
        };
    }
}
