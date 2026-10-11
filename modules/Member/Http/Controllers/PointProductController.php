<?php

namespace Modules\Member\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Member\Models\PointOrder;
use Modules\Member\Models\PointProduct;
use Modules\Member\Services\MemberService;

/**
 * 积分兑换商品
 */
class PointProductController extends Controller
{
    use ResponseTrait;

    public function index(Request $request)
    {
        $query = PointProduct::query();

        if ($request->filled('keyword')) {
            $like = '%'.str_replace('%', '\%', $request->keyword).'%';
            $query->where(function ($q) use ($like) {
                $q->where('product_no', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('spec', 'like', $like);
            });
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        return $this->paginated(
            $query->orderBy('sort')->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page)
        );
    }

    /** 上架商品列表（兑换订单创建时选商品用） */
    public function onShelf()
    {
        return $this->success(
            PointProduct::where('status', PointProduct::STATUS_ON)
                ->where('stock', '>', 0)
                ->orderBy('sort')
                ->get()
        );
    }

    public function show($id)
    {
        $product = PointProduct::find($id);
        if (! $product) {
            return $this->notFound('兑换商品不存在');
        }

        $product->recent_orders = PointOrder::where('product_id', $id)
            ->with('member:id,member_no,name,phone')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return $this->success($product);
    }

    public function store(Request $request)
    {
        $validated = $this->validateProduct($request);

        $product = PointProduct::create([
            'product_no' => MemberService::generateNo('product', 'point_products', 'product_no'),
            'name' => $validated['name'],
            'image' => $validated['image'] ?? null,
            'spec' => $validated['spec'] ?? null,
            'points' => (int) $validated['points'],
            'stock' => (int) ($validated['stock'] ?? 0),
            'exchanged' => 0,
            'limit_per_person' => (int) ($validated['limit_per_person'] ?? 0),
            'status' => (int) ($validated['status'] ?? PointProduct::STATUS_ON),
            'sort' => (int) ($validated['sort'] ?? 0),
            'remark' => $validated['remark'] ?? null,
        ]);

        return $this->created($product, '兑换商品创建成功');
    }

    public function update(Request $request, $id)
    {
        $product = PointProduct::find($id);
        if (! $product) {
            return $this->notFound('兑换商品不存在');
        }

        $validated = $this->validateProduct($request);

        $product->update([
            'name' => $validated['name'],
            'image' => $validated['image'] ?? null,
            'spec' => $validated['spec'] ?? null,
            'points' => (int) $validated['points'],
            // 库存可直接调整；已兑换部分由 point_orders 记录，
            // 库存不足时审核兑换订单会拦下，不必在此限制编辑。
            'stock' => (int) $validated['stock'],
            'limit_per_person' => (int) ($validated['limit_per_person'] ?? 0),
            'status' => (int) ($validated['status'] ?? $product->status),
            'sort' => (int) ($validated['sort'] ?? $product->sort),
            'remark' => $validated['remark'] ?? null,
        ]);

        return $this->success(PointProduct::find($id), '兑换商品已更新');
    }

    public function destroy($id)
    {
        $product = PointProduct::find($id);
        if (! $product) {
            return $this->notFound('兑换商品不存在');
        }

        // 有未终态兑换订单时禁止删除，避免订单悬挂
        $pending = PointOrder::where('product_id', $id)
            ->whereNotIn('status', [PointOrder::STATUS_COMPLETED, PointOrder::STATUS_REJECTED, PointOrder::STATUS_CANCELLED])
            ->count();

        if ($pending > 0) {
            return $this->error("该商品有 {$pending} 笔未完结兑换订单，不能删除", 422);
        }

        $product->delete();

        return $this->success(null, '兑换商品已删除');
    }

    /** 上架 */
    public function onShelfAction($id)
    {
        return $this->setStatus($id, PointProduct::STATUS_ON, '已上架');
    }

    /** 下架 */
    public function offShelfAction($id)
    {
        return $this->setStatus($id, PointProduct::STATUS_OFF, '已下架');
    }

    private function setStatus($id, int $status, string $message)
    {
        $product = PointProduct::find($id);
        if (! $product) {
            return $this->notFound('兑换商品不存在');
        }
        $product->status = $status;
        $product->save();

        return $this->success($product, $message);
    }

    private function validateProduct(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'image' => 'nullable|string|max:255',
            'spec' => 'nullable|string|max:100',
            'points' => 'required|integer|min:1',
            'stock' => 'nullable|integer|min:0',
            'limit_per_person' => 'nullable|integer|min:0',
            'status' => 'nullable|integer|in:0,1',
            'sort' => 'nullable|integer|min:0',
            'remark' => 'nullable|string|max:1000',
        ]);
    }
}
