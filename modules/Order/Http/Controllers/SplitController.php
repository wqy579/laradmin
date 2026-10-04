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
        $query = Split::with(['warehouse', 'parentProduct', 'creator', 'approver']);
        if ($request->filled('keyword')) {
            $kw = $request->keyword;
            $query->where(function ($q) use ($kw) {
                $q->where('split_no', 'like', "%{$kw}%")
                    ->orWhere('parent_product_name', 'like', "%{$kw}%");
            });
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
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

        return $this->paginated($list);
    }

    public function show($id)
    {
        $split = Split::with(['warehouse', 'parentProduct', 'creator', 'approver', 'items.product'])
            ->find($id);
        if ($split === null) {
            return $this->notFound();
        }

        return $this->success($split);
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
        $adminName = $admin?->name ?? ($admin?->nickname ?? '管理员');

        try {
            $split = $this->assemblyService->approveSplit(
                $split, $adminId, $adminName, $validated['approval_comment'] ?? null
            );
        } catch (StockRuleException $e) {
            return $this->error($e->getMessage(), 422);
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
        $validated = $request->validate(['approval_comment' => 'nullable|string|max:500']);
        $split->status = 'draft';
        $split->approval_comment = $validated['approval_comment'] ?? null;
        $split->save();

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

        return $this->noContent();
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
