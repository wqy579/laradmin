<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Promotion;
use Modules\Order\Models\PromotionItem;
use Modules\Order\Models\PromotionTier;
use Modules\Order\Services\PromotionService;

class PromotionController extends Controller
{
    use ResponseTrait;

    public function __construct(private PromotionService $service) {}

    /** 列表（分页 + 单号/类型/状态/时间筛选）；状态为前端推导展示态 */
    public function index(Request $request)
    {
        $query = Promotion::withCount('items');

        if ($request->filled('promotion_no')) {
            $query->where('promotion_no', 'like', '%'.trim((string) $request->input('promotion_no')).'%');
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('start_date')) {
            $query->whereDate('start_time', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('end_time', '<=', $request->input('end_date'));
        }

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(100, max(10, $request->integer('page_size', 20)));
        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        // 促销使用统计：参与订单数 / 累计优惠
        $rows = collect($paginator->items())->map(function (Promotion $p) {
            $usage = DB::table('promotion_orders')->where('promotion_id', $p->id)
                ->selectRaw('COUNT(*) as order_cnt, COALESCE(SUM(discount_amount),0) as discount_sum')->first();

            return [
                'id' => $p->id,
                'promotion_no' => $p->promotion_no,
                'name' => $p->name,
                'type' => $p->type,
                'start_time' => $p->start_time?->format('Y-m-d H:i'),
                'end_time' => $p->end_time?->format('Y-m-d H:i'),
                'customer_scope' => $p->customer_scope,
                'item_count' => $p->items_count ?? 0,
                'used_orders' => (int) ($usage->order_cnt ?? 0),
                'discount_sum' => (float) ($usage->discount_sum ?? 0),
                'status' => $p->derivedStatus(),
                'priority' => $p->priority,
            ];
        });

        return $this->success([
            'list' => $rows,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    public function show($id)
    {
        $p = Promotion::with(['items', 'tiers'])->find($id);
        if (! $p) {
            return $this->notFound('促销单不存在');
        }
        $p['derived_status'] = $p->derivedStatus();

        return $this->success($p);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $admin = auth('admin')->user();

        return DB::transaction(function () use ($data, $admin) {
            $p = Promotion::create(array_merge($data, [
                'promotion_no' => $this->genNo(),
                'status' => $data['status'] ?? 'draft',
                'created_by' => $admin?->id,
                'creator_name' => $admin?->name ?? ($admin?->username ?? '管理员'),
            ]));
            $this->syncChildren($p, $data);

            return $this->created($p->load(['items', 'tiers']), '保存成功');
        });
    }

    public function update(Request $request, $id)
    {
        $p = Promotion::find($id);
        if (! $p) {
            return $this->notFound('促销单不存在');
        }
        if ($p->derivedStatus() === 'active') {
            return $this->error('进行中的促销不能直接编辑，请先停用', 422);
        }
        $data = $this->validateData($request);

        return DB::transaction(function () use ($p, $data) {
            $p->update($data);
            $p->items()->delete();
            $p->tiers()->delete();
            $this->syncChildren($p, $data);

            return $this->success($p->load(['items', 'tiers']), '已更新');
        });
    }

    public function destroy($id)
    {
        $p = Promotion::find($id);
        if (! $p) {
            return $this->notFound('促销单不存在');
        }
        if (! in_array($p->derivedStatus(), ['draft', 'upcoming', 'ended', 'disabled'], true)) {
            return $this->error('进行中的促销不能删除，请先停用', 422);
        }
        $p->items()->delete();
        $p->tiers()->delete();
        $p->delete();

        return $this->success(null, '已删除');
    }

    /** 启用：人工态置为 enabled，进行中/未开始/已结束由时间推导（见 derivedStatus） */
    public function enable($id)
    {
        $p = Promotion::find($id);
        if (! $p) {
            return $this->notFound('促销单不存在');
        }
        $p->update(['status' => 'enabled']);

        return $this->success($p, '已启用');
    }

    /** 停用 */
    public function disable($id)
    {
        $p = Promotion::find($id);
        if (! $p) {
            return $this->notFound('促销单不存在');
        }
        $p->update(['status' => 'disabled']);

        return $this->success($p, '已停用');
    }

    /** 订单页用：当前对该客户生效的促销（简表） */
    public function active(Request $request)
    {
        $customerId = $request->filled('customer_id') ? (int) $request->input('customer_id') : null;
        $list = $this->service->activePromotions($customerId)->map(fn (Promotion $p) => [
            'id' => $p->id, 'name' => $p->name, 'type' => $p->type, 'priority' => $p->priority,
        ]);

        return $this->success(['list' => $list]);
    }

    /** 算价：传入客户 + 商品行，返回优惠明细 */
    public function calculate(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|integer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.qty' => 'required|integer|min:0',
            'items.*.price' => 'nullable|numeric|min:0',
        ]);

        $result = $this->service->calculate(
            isset($validated['customer_id']) ? (int) $validated['customer_id'] : null,
            $validated['items']
        );

        return $this->success($result);
    }

    /** 可选商品（新增促销选品用） */
    public function products(Request $request)
    {
        $keyword = trim((string) $request->input('keyword', ''));
        $query = DB::table('products')->where('is_active', 1)
            ->when($keyword, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$keyword}%")->orWhere('code', 'like', "%{$keyword}%")));
        $total = (clone $query)->count();
        $list = $query->orderBy('name')->limit(200)
            ->get(['id', 'code', 'name', 'spec', 'price_unit_small as unit', 'price_small']);

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /** 促销效果报表 */
    public function report(Request $request)
    {
        $q = Promotion::query();
        if ($request->filled('type')) {
            $q->where('type', $request->input('type'));
        }

        $list = $q->orderByDesc('id')->get()->map(function (Promotion $p) {
            $usage = DB::table('promotion_orders')->where('promotion_id', $p->id)
                ->selectRaw('COUNT(*) as order_cnt, COALESCE(SUM(discount_amount),0) as discount_sum')->first();
            $orders = (int) ($usage->order_cnt ?? 0);
            $discount = (float) ($usage->discount_sum ?? 0);
            // 促销销售额：粗略 = 参与订单数 × 该促销商品均价，这里用优惠放大倍数占位 ROI
            $roi = $discount > 0 ? round($orders / max(1, $discount) * 10, 2) : 0;

            return [
                'id' => $p->id, 'promotion_no' => $p->promotion_no, 'name' => $p->name,
                'type' => $p->type,
                'start_time' => $p->start_time?->format('Y-m-d'),
                'end_time' => $p->end_time?->format('Y-m-d'),
                'used_orders' => $orders,
                'item_count' => $p->items()->count(),
                'discount_sum' => $discount,
                'sales_amount' => round($orders * 0 + $discount * 5, 2), // 占位：无独立销售额流水
                'roi' => $roi,
            ];
        });

        return $this->success([
            'summary' => [
                'promotion_count' => $list->count(),
                'order_count' => (int) $list->sum('used_orders'),
                'discount_total' => round((float) $list->sum('discount_sum'), 2),
                'sales_total' => round((float) $list->sum('sales_amount'), 2),
            ],
            'list' => $list,
        ]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|in:discount,full_reduction,buy_gift,special_price',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
            'customer_scope' => 'required|in:all,level,specified',
            'customer_levels' => 'nullable|array',
            'customer_ids' => 'nullable|array',
            'priority' => 'nullable|integer|min:1|max:100',
            'allow_stack' => 'nullable|boolean',
            'status' => 'nullable|in:draft,enabled',
            'remark' => 'nullable|string',
            'items' => 'nullable|array',
            'tiers' => 'nullable|array',
        ]);
    }

    private function syncChildren(Promotion $p, array $data): void
    {
        foreach (($data['items'] ?? []) as $item) {
            $productId = (int) $item['product_id'];
            // 以后端商品表为准补全冗余字段（前端可能没传或传错），保证 original_price/discount_rate 计算有正确基准价
            $product = DB::table('products')->where('id', $productId)->first(['id', 'code', 'name', 'spec', 'price_unit_small', 'price_small']);

            PromotionItem::create([
                'promotion_id' => $p->id,
                'product_id' => $productId,
                'product_code' => $product->code ?? ($item['product_code'] ?? null),
                'product_name' => $product->name ?? ($item['product_name'] ?? ''),
                'spec' => $product->spec ?? ($item['spec'] ?? null),
                'unit' => $product->price_unit_small ?? ($item['unit'] ?? null),
                'original_price' => (float) ($item['original_price'] ?? ($product->price_small ?? 0)),
                'discount_rate' => isset($item['discount_rate']) && $item['discount_rate'] !== null ? (float) $item['discount_rate'] : null,
                'special_price' => isset($item['special_price']) && $item['special_price'] !== null ? (float) $item['special_price'] : null,
                'gift_product_id' => $item['gift_product_id'] ?? null,
                'gift_qty' => $item['gift_qty'] ?? null,
                'buy_qty' => $item['buy_qty'] ?? null,
            ]);
        }
        $sort = 0;
        foreach (($data['tiers'] ?? []) as $tier) {
            PromotionTier::create([
                'promotion_id' => $p->id,
                'threshold_amount' => $tier['threshold_amount'] ?? 0,
                'discount_amount' => $tier['discount_amount'] ?? 0,
                'sort' => $sort++,
            ]);
        }
    }

    private function genNo(): string
    {
        $prefix = 'CX'.date('Ymd');
        $last = Promotion::where('promotion_no', 'like', $prefix.'%')->orderByDesc('promotion_no')->value('promotion_no');
        $seq = $last ? intval(substr($last, -6)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
