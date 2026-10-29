<?php

namespace Modules\Mall\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Mall\Models\MallProduct;
use Modules\Stock\Models\Product;

/**
 * F5 分销商城 —— 商品（上架管理 + 前台目录）。
 *
 * 单一数据源：价格/规格/换算一律读 products，mall_products 只存上架与展示字段。
 * 「价格同步」是刷新读（把 products 当前价带回前台），不落独立价格列。
 */
class MallProductController extends Controller
{
    /** 后台商品池：未上架的可选商品列表 */
    public function options(Request $request)
    {
        $keyword = $request->input('keyword');

        $pool = Product::query()
            ->where('is_active', true)
            ->when($keyword, fn ($q) => $q->where(function ($q) use ($keyword) {
                $q->where('name', 'like', '%'.$keyword.'%')
                    ->orWhere('code', 'like', '%'.$keyword.'%');
            }))
            ->orderBy('id', 'desc')
            ->paginate($request->integer('page_size', 20));

        $items = collect($pool->items())->map(fn (Product $p) => $this->productCard($p))->all();

        return $this->success([
            'list' => $items,
            'total' => $pool->total(),
            'page' => $pool->currentPage(),
            'page_size' => $pool->perPage(),
            'last_page' => $pool->lastPage(),
        ]);
    }

    /** 后台：上架商品列表（含从 products 读到的实时价） */
    public function index(Request $request)
    {
        $query = MallProduct::query()->with('product')
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $q->whereHas('product', fn ($pq) => $pq->where('name', 'like', '%'.$request->keyword.'%')
                    ->orWhere('code', 'like', '%'.$request->keyword.'%'));
            })
            ->when($request->filled('is_on_shelf'), fn ($q) => $q->where('is_on_shelf', (bool) $request->is_on_shelf));

        $rows = $query->orderBy('sort')->orderByDesc('id')->paginate($request->integer('page_size', 20));

        $items = collect($rows->items())->map(fn (MallProduct $mp) => array_merge(
            $this->productCard($mp->product),
            [
                'id' => $mp->id,
                'product_id' => $mp->product_id,
                'is_on_shelf' => $mp->is_on_shelf,
                'sort' => $mp->sort,
                'slogan' => $mp->slogan,
                'remark' => $mp->remark,
                'created_at' => optional($mp->created_at)->toDateTimeString(),
            ]
        ))->all();

        return $this->success([
            'list' => $items,
            'total' => $rows->total(),
            'page' => $rows->currentPage(),
            'page_size' => $rows->perPage(),
            'last_page' => $rows->lastPage(),
        ]);
    }

    /** 后台：上架一个商品（幂等：product_id 唯一） */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'sort' => 'nullable|integer|min:0',
            'slogan' => 'nullable|string|max:200',
            'remark' => 'nullable|string',
        ]);

        if (MallProduct::where('product_id', $validated['product_id'])->exists()) {
            return $this->error('该商品已上架，请直接编辑');
        }

        $mp = MallProduct::create([
            'product_id' => $validated['product_id'],
            'is_on_shelf' => true,
            'sort' => $validated['sort'] ?? 0,
            'slogan' => $validated['slogan'] ?? null,
            'remark' => $validated['remark'] ?? null,
            'created_by' => auth('admin')->id(),
        ]);

        return $this->created($mp, '上架成功');
    }

    /** 后台：编辑上架字段（排序/宣传语/备注） */
    public function update(Request $request, int $id)
    {
        $mp = MallProduct::findOrFail($id);
        $validated = $request->validate([
            'sort' => 'nullable|integer|min:0',
            'slogan' => 'nullable|string|max:200',
            'remark' => 'nullable|string',
        ]);

        $mp->update($validated);

        return $this->success($mp, '保存成功');
    }

    /** 后台：上架/下架切换 */
    public function toggle(Request $request, int $id)
    {
        $mp = MallProduct::findOrFail($id);
        $on = (bool) $request->input('is_on_shelf', ! $mp->is_on_shelf);
        $mp->update(['is_on_shelf' => $on]);

        return $this->success($mp->only(['id', 'is_on_shelf']), $on ? '已上架' : '已下架');
    }

    /** 后台：删除上架记录（不删商品本身） */
    public function destroy(int $id)
    {
        $mp = MallProduct::findOrFail($id);
        $mp->delete();

        return $this->success(null, '已移除');
    }

    /**
     * 价格同步：把 products 的当前价刷回商城侧。
     * 单一数据源下本就是读 products，此操作返回同步结果（条数），
     * 前端据此刷新列表，向用户传达「价格已对齐后台」。
     */
    public function syncPrice()
    {
        $count = MallProduct::query()->count();

        return $this->success([
            'synced_count' => $count,
            'synced_at' => now()->toDateTimeString(),
        ], '价格已与后台价同步（单一数据源）');
    }

    /** 前台目录：仅上架商品，附带 products 实时价 */
    public function catalog(Request $request)
    {
        $rows = MallProduct::query()
            ->with('product')
            ->where('is_on_shelf', true)
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $q->whereHas('product', fn ($pq) => $pq->where('name', 'like', '%'.$request->keyword.'%')
                    ->orWhere('code', 'like', '%'.$request->keyword.'%'));
            })
            ->orderBy('sort')->orderByDesc('id')
            ->paginate($request->integer('page_size', 12));

        $items = collect($rows->items())->map(fn (MallProduct $mp) => $this->productCard($mp->product) + [
            'mall_product_id' => $mp->id,
            'slogan' => $mp->slogan,
            'is_on_shelf' => $mp->is_on_shelf,
        ])->all();

        return $this->success([
            'list' => $items,
            'total' => $rows->total(),
            'page' => $rows->currentPage(),
            'page_size' => $rows->perPage(),
            'last_page' => $rows->lastPage(),
        ]);
    }

    /** 前台下单可选择的分销商客户（带坐标/地址） */
    public function shopCustomers(Request $request)
    {
        $keyword = $request->input('keyword');
        $rows = DB::table('customers')
            ->where('is_active', true)
            ->when($keyword, fn ($q) => $q->where(function ($q) use ($keyword) {
                $q->where('name', 'like', '%'.$keyword.'%')
                    ->orWhere('code', 'like', '%'.$keyword.'%');
            }))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'code', 'name', 'address', 'latitude', 'longitude', 'phone'])
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'address' => $c->address,
                'phone' => $c->phone,
                'lat' => $c->latitude !== null ? (float) $c->latitude : null,
                'lng' => $c->longitude !== null ? (float) $c->longitude : null,
            ])
            ->values();

        return $this->success(['customers' => $rows]);
    }

    /** 组装前台商品卡（一律读 products 单一数据源） */
    private function productCard(?Product $p): array
    {
        if (! $p) {
            return [];
        }

        return [
            'product_id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'spec' => $p->spec,
            'image' => $p->image,
            'spec_display' => $p->spec_display,
            'conversion_display' => $p->conversion_display,
            'unit_large' => $p->price_unit ?: '件',
            'unit_medium' => $p->barcode_medium_unit ?: '盒',
            'unit_small' => $p->price_unit_small ?: '个',
            'unit_conversion' => (int) ($p->unit_conversion ?? 0),
            'unit_conversion_medium' => (int) ($p->unit_conversion_medium ?? 0),
            'price_large' => (float) ($p->price_large ?? 0),
            'price_medium' => (float) ($p->price_medium ?? 0),
            'price_small' => (float) ($p->price_small ?? 0),
        ];
    }
}
