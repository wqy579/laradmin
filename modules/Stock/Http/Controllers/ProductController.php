<?php

namespace Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\ProductCategory;
use Modules\Stock\Models\Unit;

class ProductController extends Controller
{
    /**
     * 返回三栏分类树 + 分页产品列表
     */
    public function index(Request $request)
    {
        $mainCategories = ProductCategory::where('is_main', true)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with(['subCategories' => function ($q) {
                $q->where('is_active', true)->orderBy('sort_order');
            }])
            ->get()
            ->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'children' => $cat->subCategories->map(fn ($sc) => [
                    'id' => $sc->id,
                    'name' => $sc->name,
                ]),
            ]);

        $subCategories = ProductCategory::where('is_main', false)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($cat) => ['id' => $cat->id, 'name' => $cat->name]);

        $warehouseId = $request->filled('warehouse_id') ? $request->integer('warehouse_id') : null;
        $withStock = $request->boolean('with_stock');

        $query = Product::with(['mainCategory', 'subCategory']);
        if ($request->filled('keyword')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->keyword.'%')
                    ->orWhere('code', 'like', '%'.$request->keyword.'%')
                    ->orWhere('barcode_small', 'like', '%'.$request->keyword.'%')
                    ->orWhere('spec', 'like', '%'.$request->keyword.'%');
            });
        }
        if ($request->main_category_id !== null && $request->main_category_id !== '' && $request->main_category_id !== 'null') {
            $query->where('main_category_id', (int) $request->main_category_id);
        }
        if ($request->sub_category_id !== null && $request->sub_category_id !== '' && $request->sub_category_id !== 'null') {
            $query->where('sub_category_id', (int) $request->sub_category_id);
        }
        if ($request->is_online !== null && $request->is_online !== '' && $request->is_online !== 'null') {
            $query->where('is_online', (bool) $request->is_online);
        }
        if ($request->is_active !== null && $request->is_active !== '' && $request->is_active !== 'null') {
            $query->where('is_active', (bool) $request->is_active);
        }
        // 销售单弹窗下拉（with_stock=1）需按库存降序：库存越多越靠前。库存口径与库存核对
        // 「当前库存/今日库存」同源 = stocks.quantity；选了仓库取该仓实时库存，否则取
        // products.stock_qty（StockService 维护的全仓 quantity 汇总）。
        // 排序放 ORDER BY 的相关子查询里、不在 SELECT 加 join 别名——否则 paginate 的
        // count 查询会带上别名 / products.* 生成 COUNT(products.*) 等非法 SQL。
        if ($withStock) {
            if ($warehouseId) {
                $query->orderByRaw(
                    '(SELECT COALESCE(SUM(quantity), 0) FROM stocks WHERE stocks.product_id = products.id AND stocks.warehouse_id = ?) DESC',
                    [$warehouseId]
                );
            } else {
                $query->orderByDesc('products.stock_qty');
            }
        }
        $query->orderBy('products.id', 'desc');
        $products = $query->paginate($request->integer('page_size', 20));

        // 选了仓库时，批量取本页商品的该仓库存（stocks 的 product+warehouse 唯一索引，一行一值）
        $stockMap = [];
        if ($withStock && $warehouseId) {
            $stockMap = DB::table('stocks')
                ->where('warehouse_id', $warehouseId)
                ->whereIn('product_id', $products->getCollection()->pluck('id')->all())
                ->pluck('quantity', 'product_id')
                ->all();
        }

        $products->getCollection()->transform(function ($p) use ($withStock, $warehouseId, $stockMap) {
            return [
                ...$p->toArray(true),
                'main_category_name' => $p->mainCategory?->name,
                'sub_category_name' => $p->subCategory?->name,
                'stock_qty' => (int) (($withStock && $warehouseId) ? ($stockMap[$p->id] ?? 0) : ($p->stock_qty ?? 0)),
            ];
        });

        return $this->success([
            'list' => $products->items(),
            'total' => $products->total(),
            'page' => $products->currentPage(),
            'page_size' => $products->perPage(),
            'last_page' => $products->lastPage(),
            'mainCategories' => $mainCategories,
            'subCategories' => $subCategories,
        ]);
    }

    public function show(Product $product)
    {
        $product->load(['mainCategory', 'subCategory']);

        return $this->success($product);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'main_category_id' => 'nullable|exists:product_categories,id',
            'sub_category_id' => 'nullable|exists:product_categories,id',
            'code' => 'nullable|string|max:50',
            'barcode_small' => 'nullable|string|max:50',
            'price_unit' => 'nullable|string|max:20',
            'barcode_medium_unit' => 'nullable|string|max:20',
            'price_unit_small' => 'nullable|string|max:20',
            'price_large' => 'nullable|numeric|min:0',
            'price_small' => 'nullable|numeric|min:0',
            'price_medium' => 'nullable|numeric|min:0',
            'shelf_life_days' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'is_online' => 'nullable|boolean',
            'unit_conversion' => 'nullable|numeric|min:1',
            'unit_conversion_medium' => 'nullable|numeric|min:1',
        ]);
        $product = Product::create($validated);

        return $this->created($product, '创建成功');
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'main_category_id' => 'nullable|exists:product_categories,id',
            'sub_category_id' => 'nullable|exists:product_categories,id',
            'code' => 'nullable|string|max:50',
            'image' => 'nullable|string|max:500',
            'barcode_small' => 'nullable|string|max:50',
            'price_unit' => 'nullable|string|max:20',
            'barcode_medium_unit' => 'nullable|string|max:20',
            'price_unit_small' => 'nullable|string|max:20',
            'price_large' => 'nullable|numeric|min:0',
            'price_small' => 'nullable|numeric|min:0',
            'price_medium' => 'nullable|numeric|min:0',
            'shelf_life_days' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'is_online' => 'nullable|boolean',
            'unit_conversion' => 'nullable|numeric|min:1',
            'unit_conversion_medium' => 'nullable|numeric|min:1',
        ]);
        $product->update($validated);

        return $this->success($product, '更新成功');
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return $this->success(null, '删除成功');
    }

    public function batchUpdateStatus(Request $request)
    {
        $request->validate(['ids' => 'required|array', 'is_active' => 'required|boolean']);
        Product::whereIn('id', $request->ids)->update(['is_active' => $request->is_active]);

        return $this->success(null, '操作成功');
    }

    public function batchDelete(Request $request)
    {
        $request->validate(['ids' => 'required|array']);
        Product::whereIn('id', $request->ids)->delete();

        return $this->success(null, '删除成功');
    }

    // --- 单位管理 ---

    public function units()
    {
        $units = Unit::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name']);

        return $this->success($units->map(fn ($u) => ['value' => $u->name, 'label' => $u->name]));
    }

    // --- 分类管理 ---

    public function categories()
    {
        $mainCategories = ProductCategory::where('is_main', true)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with(['subCategories' => function ($q) {
                $q->where('is_active', true)->orderBy('sort_order');
            }])
            ->get();

        // 一次查出所有主/副分类的商品数，避免每个分类一条 COUNT（线上单分类下有 80+ 子分类）
        $mainCounts = Product::query()
            ->where('is_active', true)
            ->whereNotNull('main_category_id')
            ->groupBy('main_category_id')
            ->selectRaw('main_category_id, count(*) as cnt')
            ->pluck('cnt', 'main_category_id');
        $subCounts = Product::query()
            ->where('is_active', true)
            ->whereNotNull('sub_category_id')
            ->groupBy('sub_category_id')
            ->selectRaw('sub_category_id, count(*) as cnt')
            ->pluck('cnt', 'sub_category_id');

        return $this->success($mainCategories->map(fn ($cat) => [
            'id' => $cat->id,
            'name' => $cat->name,
            'product_count' => (int) ($mainCounts[$cat->id] ?? 0),
            'children' => $cat->subCategories->map(fn ($sc) => [
                'id' => $sc->id,
                'name' => $sc->name,
                'product_count' => (int) ($subCounts[$sc->id] ?? 0),
            ]),
        ]));
    }

    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'is_main' => 'required|boolean',
            'parent_id' => 'nullable|exists:product_categories,id',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);
        $category = ProductCategory::create($validated);

        return $this->created($category, '创建成功');
    }

    public function updateCategory(ProductCategory $category, Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'is_main' => 'required|boolean',
            'parent_id' => 'nullable|exists:product_categories,id',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);
        $category->update($validated);

        return $this->success($category, '更新成功');
    }

    public function destroyCategory(ProductCategory $category)
    {
        // 先解除商品对该分类的引用，否则删除后商品会挂在已不存在的分类上
        if ($category->is_main) {
            Product::where('main_category_id', $category->id)->update(['main_category_id' => null]);
        } else {
            Product::where('sub_category_id', $category->id)->update(['sub_category_id' => null]);
        }
        // 主分类被删时，其下副分类一并删除
        if ($category->is_main) {
            $childIds = ProductCategory::where('parent_id', $category->id)->pluck('id');
            if ($childIds->isNotEmpty()) {
                Product::whereIn('sub_category_id', $childIds)->update(['sub_category_id' => null]);
                ProductCategory::whereIn('id', $childIds)->delete();
            }
        }
        $category->delete();

        return $this->success(null, '删除成功');
    }
}
