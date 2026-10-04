<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Assembly;
use Modules\Order\Services\AssemblyService;
use Modules\Stock\Exceptions\StockRuleException;

class AssemblyController extends Controller
{
    use ResponseTrait;

    public function __construct(private AssemblyService $assemblyService) {}

    public function index(Request $request)
    {
        $query = Assembly::with(['warehouse', 'parentProduct', 'creator', 'approver'])->withCount('items');
        if ($request->filled('assembly_no')) {
            $query->where('assembly_no', 'like', "%{$request->assembly_no}%");
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
            $query->where('assembly_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('assembly_date', '<=', $request->end_date);
        }
        $list = $query->orderByDesc('id')->paginate(
            $request->integer('page_size', 20), ['*'], 'page', $request->integer('page', 1)
        );

        // 统一字段命名：对前端暴露 split_qty 与 assembly 对称
        $items = $list->map(function ($item) {
            return [
                ...$item->toArray(),
                'items' => $item->items->map(function ($i) {
                    return [
                        'product_id' => $i->product_id,
                        'product_name' => $i->product_name,
                        'unit_usage' => (int) $i->unit_usage,
                        'total_usage' => (int) $i->total_usage,
                        'split_qty' => (int) $i->unit_usage,
                        'split_total' => (int) $i->total_usage,
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

    /** 批量审核：勾选多张待审核单批量通过，全部在一个事务内执行库存联动 */
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
                    $assembly = Assembly::with(['items'])->find($id);
                    if (! $assembly) {
                        continue;
                    }
                    if ($assembly->status === 'approved') {
                        $skipped[] = ['id' => $id, 'reason' => '已审核'];
                        continue;
                    }
                    if ($assembly->status !== 'pending') {
                        $skipped[] = ['id' => $id, 'reason' => '非待审核'];
                        continue;
                    }
                    $this->assemblyService->approveAssembly($assembly, $adminId, $adminName, '批量审核通过');
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
        $assembly = Assembly::with(['warehouse', 'parentProduct', 'creator', 'approver', 'items.product'])
            ->find($id);
        if ($assembly === null) {
            return $this->notFound();
        }

        // 统一字段命名：对前端暴露 split_qty 与 assembly 对称
        $items = $assembly->items->map(function ($item) {
            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_code' => $item->product_code,
                'product_name' => $item->product_name,
                'spec' => $item->spec,
                'unit' => $item->unit,
                'unit_usage' => (int) $item->unit_usage,
                'total_usage' => (int) $item->total_usage,
                'split_qty' => (int) $item->unit_usage,  // 兼容字段
                'split_total' => (int) $item->total_usage, // 兼容字段
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
            ...$assembly->toArray(),
            'items' => $items,
        ]);
    }

    /** 取父件的组装 BOM 模板，供新增组装单自动带出子件 */
    public function bomByProduct(Request $request)
    {
        $request->validate(['product_id' => 'required|exists:products,id']);
        $bom = DB::table('product_bom as b')
            ->leftJoin('product_bom_items as bi', 'bi.bom_id', '=', 'b.id')
            ->leftJoin('products as p', 'p.id', '=', 'bi.product_id')
            ->where('b.product_id', $request->product_id)
            ->where('b.type', 'assembly')
            ->select(
                'b.id as bom_id', 'b.name as bom_name',
                'bi.id as item_id', 'bi.product_id', 'p.code as product_code',
                'p.name as product_name', 'p.spec', 'p.price_unit_small as unit',
                'bi.unit_usage', 'bi.unit_cost'
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
                'unit_usage' => (int) $r->unit_usage,
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
            'assembly_date' => 'required|date',
            'salesman_id' => 'nullable|integer',
            'remark' => 'nullable|string',
            'submit_for_approval' => 'nullable|boolean',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.unit_usage' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
        ]);

        $adminId = auth('admin')?->id();
        $parent = DB::table('products')->where('id', $validated['parent_product_id'])->first();
        $quantity = (int) $validated['quantity'];

        $items = [];
        $totalCost = 0.0;
        foreach ($validated['items'] as $item) {
            if ($item['product_id'] == $validated['parent_product_id']) {
                return $this->error('子件不能与父件相同', 422);
            }
            $p = DB::table('products')->where('id', $item['product_id'])->first();
            $totalUsage = (int) $item['unit_usage'] * $quantity;
            $lineCost = round($totalUsage * (float) $item['unit_cost'], 2);
            $totalCost += $lineCost;
            $items[] = [
                'product_id' => $item['product_id'],
                'product_code' => $p?->code ?? '',
                'product_name' => $p?->name ?? '',
                'spec' => $p?->spec ?? '',
                'unit' => $p?->price_unit_small ?? '',
                'unit_usage' => (int) $item['unit_usage'],
                'total_usage' => $totalUsage,
                'unit_cost' => (float) $item['unit_cost'],
                'total_cost' => $lineCost,
            ];
        }

        return DB::transaction(function () use ($validated, $parent, $quantity, $items, $totalCost, $adminId) {
            $status = ! empty($validated['submit_for_approval']) ? 'pending' : 'draft';
            $assembly = Assembly::create([
                'assembly_no' => $this->generateNo(),
                'parent_product_id' => $validated['parent_product_id'],
                'parent_product_name' => $parent?->name ?? '',
                'parent_product_code' => $parent?->code ?? '',
                'warehouse_id' => $validated['warehouse_id'],
                'quantity' => $quantity,
                'total_cost' => round($totalCost, 2),
                'unit_cost' => $quantity > 0 ? round($totalCost / $quantity, 2) : 0,
                'status' => $status,
                'salesman_id' => $validated['salesman_id'] ?? $adminId,
                'assembly_date' => $validated['assembly_date'],
                'remark' => $validated['remark'] ?? null,
                'created_by' => $adminId,
            ]);
            foreach ($items as $item) {
                $item['assembly_order_id'] = $assembly->id;
                DB::table('assembly_order_items')->insert(array_merge($item, [
                    'created_at' => now(), 'updated_at' => now(),
                ]));
            }

            return $this->created($assembly->fresh(['items.product']), $status === 'pending' ? '已提交审核' : '已保存草稿');
        });
    }

    public function update(Request $request, $id)
    {
        $assembly = Assembly::find($id);
        if ($assembly === null) {
            return $this->notFound();
        }
        if ($assembly->status !== 'draft') {
            return $this->error('只有草稿状态可以编辑', 422);
        }
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'quantity' => 'required|integer|min:1',
            'assembly_date' => 'required|date',
            'salesman_id' => 'nullable|integer',
            'remark' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.unit_usage' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
        ]);

        $quantity = (int) $validated['quantity'];
        $itemsData = [];
        $totalCost = 0.0;
        foreach ($validated['items'] as $item) {
            $p = DB::table('products')->where('id', $item['product_id'])->first();
            $totalUsage = (int) $item['unit_usage'] * $quantity;
            $lineCost = round($totalUsage * (float) $item['unit_cost'], 2);
            $totalCost += $lineCost;
            $itemsData[] = [
                'product_id' => $item['product_id'],
                'product_code' => $p?->code ?? '',
                'product_name' => $p?->name ?? '',
                'spec' => $p?->spec ?? '',
                'unit' => $p?->price_unit_small ?? '',
                'unit_usage' => (int) $item['unit_usage'],
                'total_usage' => $totalUsage,
                'unit_cost' => (float) $item['unit_cost'],
                'total_cost' => $lineCost,
            ];
        }

        return DB::transaction(function () use ($assembly, $validated, $quantity, $itemsData, $totalCost) {
            $assembly->update([
                'warehouse_id' => $validated['warehouse_id'],
                'quantity' => $quantity,
                'assembly_date' => $validated['assembly_date'],
                'salesman_id' => $validated['salesman_id'] ?? null,
                'remark' => $validated['remark'] ?? null,
                'total_cost' => round($totalCost, 2),
                'unit_cost' => $quantity > 0 ? round($totalCost / $quantity, 2) : 0,
            ]);
            DB::table('assembly_order_items')->where('assembly_order_id', $assembly->id)->delete();
            foreach ($itemsData as $item) {
                $item['assembly_order_id'] = $assembly->id;
                DB::table('assembly_order_items')->insert(array_merge($item, [
                    'created_at' => now(), 'updated_at' => now(),
                ]));
            }

            return $this->success($assembly->fresh(['items.product']), '更新成功');
        });
    }

    public function submit($id)
    {
        $assembly = Assembly::find($id);
        if ($assembly === null) {
            return $this->notFound();
        }
        if ($assembly->status !== 'draft') {
            return $this->error('只有草稿状态可以提交审核', 422);
        }
        $assembly->status = 'pending';
        $assembly->save();

        return $this->success($assembly, '已提交审核');
    }

    public function approve(Request $request, $id)
    {
        $assembly = Assembly::with(['items'])->find($id);
        if ($assembly === null) {
            return $this->notFound();
        }
        if ($assembly->status === 'approved') {
            return $this->success($assembly, '已审核，无需重复操作');
        }
        if ($assembly->status !== 'pending') {
            return $this->error('只有待审核状态可以审核', 422);
        }
        $validated = $request->validate(['approval_comment' => 'nullable|string|max:500']);
        $admin = auth('admin')->user();
        $adminId = $admin?->id;
        $adminName = $admin?->real_name ?? $admin?->username ?? ($admin?->name ?? '管理员');

        try {
            $result = $this->assemblyService->approveAssembly(
                $assembly, $adminId, $adminName, $validated['approval_comment'] ?? null
            );
            // Service 返回数组 [assembly, items]，手动拼接响应
            $assembly = (object) array_merge((array) $result['assembly'], [
                'items' => $result['items'],
            ]);
        } catch (StockRuleException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('审核失败：'.$e->getMessage(), 422);
        }

        return $this->success($assembly, '组装成功，已完成子件出库和父件入库，父件单位成本¥'.number_format((float) $assembly->unit_cost, 2));
    }

    public function reject(Request $request, $id)
    {
        $assembly = Assembly::find($id);
        if ($assembly === null) {
            return $this->notFound();
        }
        if ($assembly->status !== 'pending') {
            return $this->error('只有待审核状态可以驳回', 422);
        }
        $validated = $request->validate(['approval_comment' => 'nullable|string|max:500']);
        $assembly->status = 'draft';
        $assembly->approval_comment = $validated['approval_comment'] ?? null;
        $assembly->save();

        return $this->success($assembly, '已驳回，可继续编辑');
    }

    public function cancel($id)
    {
        $assembly = Assembly::find($id);
        if ($assembly === null) {
            return $this->notFound();
        }
        if ($assembly->status === 'approved') {
            return $this->error('已审核的组装单不能取消', 422);
        }
        $assembly->status = 'cancelled';
        $assembly->save();

        return $this->success($assembly, '已取消');
    }

    public function destroy($id)
    {
        $assembly = Assembly::find($id);
        if ($assembly === null) {
            return $this->notFound();
        }
        if ($assembly->status !== 'draft') {
            return $this->error('只有草稿状态可以删除', 422);
        }
        DB::table('assembly_order_items')->where('assembly_order_id', $assembly->id)->delete();
        $assembly->delete();

        return $this->success(null, '删除成功');
    }

    public function export(Request $request)
    {
        $request->validate([
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);
        $query = Assembly::with(['warehouse', 'creator', 'approver']);
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('start_date')) {
            $query->where('assembly_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('assembly_date', '<=', $request->end_date);
        }
        $list = $query->orderByDesc('id')->get();

        $csv = "\u{FEFF}";
        $csv .= "商品组装单列表\n\n";
        $csv .= "组装单号,父件商品,组装数量,子件种类,总成本,单位成本,仓库,组装日期,状态,制单人,审核人\n";
        foreach ($list as $r) {
            $csv .= sprintf(
                "%s,%s,%d,%d,%s,%s,%s,%s,%s,%s,%s\n",
                $r->assembly_no,
                $r->parent_product_name ?? '',
                $r->quantity,
                $r->items?->count() ?? 0,
                $r->total_cost,
                $r->unit_cost,
                $r->warehouse?->name ?? '',
                $r->assembly_date?->format('Y-m-d') ?? '',
                $this->statusLabel($r->status),
                $r->creator?->real_name ?? $r->creator?->name ?? '-',
                $r->approver?->real_name ?? $r->approver?->name ?? '-'
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="assembly_orders.csv"',
        ]);
    }

    private function generateNo(): string
    {
        $prefix = 'ZC'.date('Ymd');
        $last = Assembly::where('assembly_no', 'like', $prefix.'%')
            ->orderByDesc('assembly_no')
            ->value('assembly_no');
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
