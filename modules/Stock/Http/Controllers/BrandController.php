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
 *
 * 编码约定：BR + 4 位数字。有 value 时这个数字约定为「关联一级分类的 ID」
 * （连凯口径，与品牌=分类一级维度一致），故 BackfillBrandCategory 命令按
 * `^BR0*(\d+)$` 反解回填 category_id——新增编码时不要把品牌自身 id 填进去，
 * 否则回填会把品牌错链到别的分类。无关联分类则留空。
 */
class BrandController extends Controller
{
    use ResponseTrait;

    /** 编码格式：BR + 恰好 4 位数字（如 BR0012） */
    private const CODE_PATTERN = '/^BR\d{4}$/';

    /**
     * 列表：支持名称/编码/状态/分类筛选 + 分页。
     *
     * 商品数走一次 GROUP BY，不在 map 里逐条 COUNT——线上单个品牌下可能有
     * 2000+ 商品，逐条 COUNT 是 2000 次查询（与 ProductController::categories 同原则）。
     *
     * 筛选参数说明：
     *  - name  / code：精确到单个字段的模糊匹配（前端查询区两个独立输入框）。
     *  - keyword：历史兼容参数，匹配名称或编码任一（旧测试与旧调用方仍在用）。
     */
    public function index(Request $request)
    {
        $query = Brand::with('category:id,name');

        $likeName = $request->filled('name')
            ? '%'.str_replace('%', '\%', $request->name).'%'
            : null;
        $likeCode = $request->filled('code')
            ? '%'.str_replace('%', '\%', $request->code).'%'
            : null;
        $likeKeyword = $request->filled('keyword')
            ? '%'.str_replace('%', '\%', $request->keyword).'%'
            : null;

        // 三个模糊字段合并成一组 OR：填了 name+code 则是「名称匹配 或 编码匹配」，
        // 这是列表页两个独立输入框的常见预期，不需要用户自己加"或者"。
        if ($likeName || $likeCode || $likeKeyword) {
            $query->where(function ($q) use ($likeName, $likeCode, $likeKeyword) {
                if ($likeName) {
                    $q->where('name', 'like', $likeName);
                }
                if ($likeCode) {
                    $q->orWhere('code', 'like', $likeCode);
                }
                if ($likeKeyword) {
                    $q->orWhere('name', 'like', $likeKeyword)
                        ->orWhere('code', 'like', $likeKeyword);
                }
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

        [$data, $err] = $this->resolveCode($data);
        if ($err) {
            return $err;
        }
        // 分类冲突先于编码唯一校验：编码由分类 ID 生成，两个品牌挂同一分类必然撞码，
        // 若先查编码会给用户一句「编码已存在」，掩盖真正原因（分类已被占用）。
        if ($err = $this->categoryConflict($data['category_id'] ?? null, null)) {
            return $err;
        }
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

        // 合并已有值：前端表单始终提交完整字段，这里防御性地以「提交值 ?? 原值」取，
        // 避免只改一个字段时把另一个字段清空。
        $category_id = $data['category_id'] ?? $brand->category_id;
        [$data, $err] = $this->resolveCode($data, $brand, autoGenerate: false);
        if ($err) {
            return $err;
        }
        // 顺序同 store()：分类冲突是更根本的约束，先报它。
        if ($err = $this->categoryConflict($category_id, $id)) {
            return $err;
        }
        if ($this->codeTaken($data['code'] ?? null, $id)) {
            return $this->error('品牌编码已存在', 422);
        }
        $data['category_id'] = $category_id;

        $brand->update(array_merge($data, ['updated_by' => auth('admin')->id()]));

        // 关联分类存在时同步分类名，保持「品牌=一级分类」的展示一致（连凯口径）。
        // 仅当本次提交里确实带了名称变更才同步——只改状态/排序不应动分类名。
        // 只有「一级分类」（连凯的一级维度）才允许被品牌改名覆盖，二级分类有自己的层级。
        //
        // sync_category=false 时跳过：前端弹窗已让用户确认，若用户选「不同步」，
        // 说明这次改名不希望连带改分类（分类改名影响面更大，会影响该分类下所有商品的展示）。
        // 默认 true 保持向后兼容——旧调用方不传该参数，行为与之前一致。
        $syncCategory = $request->boolean('sync_category', true);
        if ($syncCategory && ! empty($category_id) && array_key_exists('name', $data)) {
            $category = ProductCategory::find($category_id);
            if ($category && $category->is_main && $category->name !== $data['name']) {
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

    /**
     * 导出：CSV（带 BOM，Excel 直接打开不乱码）。
     *
     * 不带筛选条件——导出全部品牌，与页面上「批量导出」的语义一致。
     * 走 CSV 而非 maatwebsite/excel：品牌只有 7 个简单字段，xlsx 的依赖与内存开销
     * 不值得；会员/订单等模块的导出也是同一套 CSV 写法。
     */
    public function export()
    {
        $brands = Brand::with('category:id,name')
            ->orderBy('sort')
            ->orderByDesc('id')
            ->get();

        $countMap = Product::query()
            ->whereNotNull('brand_id')
            ->groupBy('brand_id')
            ->selectRaw('brand_id, count(*) as cnt')
            ->pluck('cnt', 'brand_id');

        $csv = "\u{FEFF}"; // BOM：Excel 正确识别 UTF-8
        $csv .= "品牌档案\n\n";
        $csv .= "品牌编码,品牌名称,关联分类,商品数量,排序,状态,创建时间\n";

        foreach ($brands as $b) {
            $csv .= sprintf(
                "%s,%s,%s,%d,%d,%s,%s\n",
                $this->csvCell($b->code ?? ''),
                $this->csvCell($b->name),
                $this->csvCell($b->category?->name ?? ''),
                (int) ($countMap[$b->id] ?? 0),
                (int) $b->sort,
                $b->is_active ? '启用' : '停用',
                $b->created_at?->format('Y-m-d H:i') ?? '',
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="brands.csv"',
        ]);
    }

    /**
     * 解析品牌编码。
     *
     * 返回 [$data, ?Response]：第二个元素非空表示有错误响应，调用方直接 return。
     *
     * - 显式提交（非空）：校验格式后原样采用。
     * - 未提交且 autoGenerate=true（新增）：按关联分类生成 BR+4 位分类 ID（左补零）；
     *   无关联分类则留空。不取品牌自身 id——BackfillBrandCategory 会把 BR 后的数字
     *   当作分类 ID 回填 category_id，用品牌 id 会造成错误关联。
     * - 未提交且 autoGenerate=false（编辑）：**不动编码**，从 $data 里剔除 code，
     *   让 update() 保留库里的原值。前端改单个字段时不带 code 是正常情况，
     *   若在此处自动生成会覆盖掉用户手填的编码。
     */
    private function resolveCode(array $data, ?Brand $brand = null, bool $autoGenerate = true): array
    {
        $code = $data['code'] ?? null;
        if ($code !== null && trim((string) $code) !== '') {
            $code = trim((string) $code);
            if (! preg_match(self::CODE_PATTERN, $code)) {
                return [$data, $this->error('品牌编码格式应为 BR+4 位数字（如 BR0012）', 422)];
            }
            $data['code'] = $code;

            return [$data, null];
        }

        if (! $autoGenerate) {
            unset($data['code']);

            return [$data, null];
        }

        $categoryId = (int) ($data['category_id'] ?? $brand?->category_id ?? 0);
        $data['code'] = $categoryId > 0
            ? 'BR'.str_pad((string) $categoryId, 4, '0', STR_PAD_LEFT)
            : null;

        return [$data, null];
    }

    /**
     * 校验一个一级分类是否已关联其他品牌（品牌↔分类按设计是一对一）。
     *
     * 返回 null 表示无冲突；非空为 422 错误响应。
     */
    private function categoryConflict(?int $categoryId, ?int $ignoreId)
    {
        if (empty($categoryId)) {
            return null;
        }

        $other = Brand::where('category_id', $categoryId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->first();

        if ($other) {
            return $this->error("该分类已关联品牌「{$other->name}」，请更换分类", 422);
        }

        return null;
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'nullable|string|max:6',
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

    /** CSV 单元格转义：含引号/逗号/换行时用双引号包裹并转义内部引号 */
    private function csvCell($value): string
    {
        $value = (string) ($value ?? '');
        if (preg_match('/[",\r\n]/', $value)) {
            $value = '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
