<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Business\Models\Product;
use Modules\Business\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 成本价格管理：产品成本价列表查询与维护
 */
class CostPriceController extends Controller
{
    /**
     * 成本价格列表：支持关键词搜索与成本价状态筛选
     */
    public function index(Request $request)
    {
        $query = Product::query();

        if ($request->filled('keyword')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->keyword . '%')
                  ->orWhere('code', 'like', '%' . $request->keyword . '%')
                  ->orWhere('barcode_small', 'like', '%' . $request->keyword . '%')
                  ->orWhere('barcode_large', 'like', '%' . $request->keyword . '%')
                  ->orWhere('spec', 'like', '%' . $request->keyword . '%');
            });
        }

        // cost 筛选：set=有成本价，unset=未设置成本价
        if ($request->cost === 'set') {
            $query->where('cost_price', '>', 0);
        } elseif ($request->cost === 'unset') {
            $query->where(function ($q) {
                $q->whereNull('cost_price')->orWhere('cost_price', '<=', 0);
            });
        }

        // 分类筛选（左侧主分类 / 中栏副分类）
        if ($request->filled('main_category_id')) {
            $query->where('main_category_id', (int) $request->main_category_id);
        }
        if ($request->filled('sub_category_id')) {
            $query->where('sub_category_id', (int) $request->sub_category_id);
        }

        $query->orderByDesc('id');
        $products = $query->paginate($request->integer('page_size', 30));

        $items = $products->items();

        $counts = [
            'all' => Product::count(),
            'set' => Product::where('cost_price', '>', 0)->count(),
            'unset' => Product::where(function ($q) {
                $q->whereNull('cost_price')->orWhere('cost_price', '<=', 0);
            })->count(),
        ];

        return $this->success([
            'list' => $items,
            'total' => $products->total(),
            'page' => $products->currentPage(),
            'page_size' => $products->perPage(),
            'last_page' => $products->lastPage(),
            'counts' => $counts,
        ]);
    }

    /**
     * 更新单个产品成本价，并同步所有库存记录的成本价与库存金额
     */
    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'cost_price' => 'required|numeric|min:0',
        ]);

        try {
            DB::transaction(function () use ($product, $validated) {
                $cost = (float) $validated['cost_price'];
                $product->update(['cost_price' => $cost]);

                // 同步库存成本价与库存金额
                Stock::where('product_id', $product->id)->update([
                    'cost_price' => $cost,
                    'total_amount' => DB::raw('quantity * ' . $cost),
                ]);
            });

            return $this->success($product->fresh(), '保存成功');
        } catch (\Exception $e) {
            return $this->error('保存失败：' . $e->getMessage());
        }
    }

    /**
     * 批量设置产品成本价，并同步各产品库存记录
     */
    public function batchUpdate(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'cost_price' => 'required|numeric|min:0',
        ]);

        try {
            $ids = array_values(array_filter($validated['ids'], fn ($id) => $id > 0));
            $cost = (float) $validated['cost_price'];

            DB::transaction(function () use ($ids, $cost) {
                Product::whereIn('id', $ids)->update(['cost_price' => $cost]);
                Stock::whereIn('product_id', $ids)->update([
                    'cost_price' => $cost,
                    'total_amount' => DB::raw('quantity * ' . $cost),
                ]);
            });

            return $this->success(null, '批量设置成功');
        } catch (\Exception $e) {
            return $this->error('批量设置失败：' . $e->getMessage());
        }
    }
}
