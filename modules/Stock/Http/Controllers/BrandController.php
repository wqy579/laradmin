<?php

namespace Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Stock\Models\Brand;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\ProductCategory;

/**
 * 品牌档案管理。
 *
 * 品牌是报表的一级汇总维度（销售/库存按品牌筛选与分组），同时是商品档案的
 * 可选属性。分类本身仍由 ProductController::categories 管理，本控制器只负责
 * 品牌主数据与「品牌 ↔ 一级分类」的关联关系。
 */
class BrandController extends Controller
{
    use ResponseTrait;

    /**
     * 列表：支持名称/编码/状态筛选 + 分页。
     *
     * 商品数走一次 GROUP BY，不在 map 里逐条 COUNT——线上单个品牌下可能有
     * 2000+ 商品，逐条 COUNT 是 2000 次查询（与 ProductController::categories 同原则）。
     */
    public function index(Request $request)
    {
        $query = Brand::with('category:id,name');

        if ($request->filled('keyword')) {
            $like = '%'.str_replace('%', '\%', $request->keyword).'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)->orWhere('code', 'like', $like);
            });
        }
        if ($request->has('is_active')) {
            $query->where('is_active', (bool) $request->is_active);
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->category_id);
        }

        $brands = $query->orderBy('sort')->orderByDesc('id')->paginate($request->integer('page_size', 20));

        $countMap = Product::query()
            ->whereNotNull('brand_id')
            ->groupBy('brand_id')
            ->selectRaw('brand_id, count(*) as cnt')
            ->pluck('cnt', 'brand_id');

        $brands->getCollection()->transform(function (Brand $brand) use ($countMap) {
            $brand['product_count'] = (int) ($countMap[$brand->id] ?? 0);

            return $brand;
        });

        return $this->paginated($brands);
    }

    /** 下拉选项（商品表单用）：只返回启用品牌，按商品数降序 */
    public function options()
    {
        $countMap = Product::query()
            ->whereNotNull('brand_id')
            ->groupBy('brand_id')
            ->selectRaw('brand_id, count(*) as cnt')
            ->pluck('cnt', 'brand_id');

        $list = Brand::where('is_active', true)
            ->orderByDesc('sort')
            ->orderBy('name')
            ->get()
            ->map(fn (Brand $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'code' => $b->code,
                'product_count' => (int) ($countMap[$b->id] ?? 0),
            ]);

        return $this->success(['list' => $list]);
    }

    /** 详情：含该品牌下商品概要 */
    public function show($id)
    {
        $brand = Brand::with('category:id,name')->find($id);
        if (! $brand) {
            return $this->notFound('品牌不存在');
        }
        $brand['product_count'] = $brand->productsCount();
        $brand['products'] = $brand->products()
            ->select('id', 'name', 'code', 'spec', 'price_small', 'is_active')
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get();

        return $this->success($brand);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        if ($this->codeTaken($data['code'] ?? null, null)) {
            return $this->error('品牌编码已存在', 422);
        }
        $brand = Brand::create(array_merge($data, ['created_by' => auth('admin')->id()]));

        return $this->created($brand, '已创建');
    }

    public function update(Request $request, $id)
    {
        $brand = Brand::find($id);
        if (! $brand) {
            return $this->notFound('品牌不存在');
        }
        $data = $this->validateData($request);
        if ($this->codeTaken($data['code'] ?? null, $id)) {
            return $this->error('品牌编码已存在', 422);
        }
        $brand->update(array_merge($data, ['updated_by' => auth('admin')->id()]));

        // 关联分类存在时同步分类名，保持「品牌=一级分类」的展示一致（连凯口径）。
        // validate() 只回传本次提交的字段，编辑分类名时 category_id 通常在品牌上而非 $data 里，
        // 所以取「本次提交的值 ?? 品牌已有值」。
        $categoryId = $data['category_id'] ?? $brand->category_id;
        if (! empty($categoryId) && isset($data['name'])) {
            $category = ProductCategory::find($categoryId);
            if ($category && $category->name !== $data['name']) {
                $category->update(['name' => $data['name']]);
            }
        }

        return $this->success($brand->fresh(), '已更新');
    }

    /**
     * 删除：有关联商品则拒绝。
     *
     * 不物理删除、走软删——历史订单里的 brand_id 不能变成孤儿外键。
     * 已软删的品牌不会再出现在 options() 与 dashboard 统计里。
     */
    public function destroy($id)
    {
        $brand = Brand::find($id);
        if (! $brand) {
            return $this->notFound('品牌不存在');
        }
        $count = $brand->productsCount();
        if ($count > 0) {
            return $this->error("该品牌下有 {$count} 个商品，请先将商品迁移到其他品牌后再删除", 422);
        }
        $brand->delete();

        return $this->success(null, '已删除');
    }

    /** 批量启用/停用：status=true 启用，false 停用 */
    public function batchUpdateStatus(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'is_active' => 'required|boolean',
        ]);
        Brand::whereIn('id', $data['ids'])->update([
            'is_active' => $data['is_active'],
            'updated_by' => auth('admin')->id(),
        ]);

        return $this->success(['affected' => count($data['ids'])], '已更新');
    }

    private function validateData(Request $request, ?Brand $brand = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'nullable|string|max:50',
            'category_id' => 'nullable|integer|exists:product_categories,id',
            'sort' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'remark' => 'nullable|string|max:500',
        ]);
    }

    private function codeTaken(?string $code, ?int $ignoreId): bool
    {
        if (empty($code)) {
            return false;
        }
        return Brand::where('code', $code)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
    }
}
