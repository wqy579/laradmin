<?php

namespace Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Models\CustomerLevel;
use Modules\Stock\Models\CustomerSpecialPrice;
use Modules\Stock\Models\PricePlan;

/**
 * F7 价格方案 + 客户特价。
 *
 * 职责边界：
 *  - 价格方案（price_plans）：命名一套定价策略，作为客户特价的政策头。
 *  - 客户特价（customer_special_price）：客户 × 商品 的特价落账。
 *  - resolve：按 E4 口径 特价 > 等级价 > 默认价 解析有效售价，输出列形状与
 *    PriceRankController::resolveCustomerPrices() 完全一致，供前端复用 ReportTable.vue
 *    的 price_tag 列 + rule_hit 整行高亮（不改写 modules/Report）。
 */
class CustomerSpecialPriceController extends Controller
{
    use ResponseTrait;

    // ------------------------------------------------------------------
    // 价格方案 CRUD
    // ------------------------------------------------------------------

    public function plans(Request $request)
    {
        $query = PricePlan::query()->with('level')
            ->when($request->filled('keyword'), fn ($q) => $q->where(function ($w) use ($request) {
                $w->where('name', 'like', "%{$request->keyword}%")
                    ->orWhere('code', 'like', "%{$request->keyword}%");
            }));

        $total = (clone $query)->count();
        $page = max(1, $request->integer('page', 1));
        $pageSize = min(100, max(10, $request->integer('page_size', 20)));
        $list = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        return $this->success([
            'list' => $list->items(), 'total' => $total, 'page' => $page, 'page_size' => $pageSize,
        ]);
    }

    public function storePlan(Request $request)
    {
        $data = $this->validatePlan($request);

        if (PricePlan::where('code', $data['code'])->exists()) {
            return $this->error('方案编码已存在', 422);
        }

        $plan = PricePlan::create($data);

        return $this->created($plan, '方案已创建');
    }

    public function updatePlan(Request $request, $id)
    {
        $plan = PricePlan::find($id);
        if ($plan === null) {
            return $this->notFound('方案不存在');
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:50',
            'code' => 'sometimes|required|string|max:30',
            'type' => 'sometimes|required|in:special_price,level_price,default_price',
            'discount' => 'nullable|numeric|min:0.1|max:9.9',
            'level_id' => 'nullable|integer|exists:customer_levels,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'nullable|boolean',
            'remark' => 'nullable|string|max:255',
        ]);

        if (! empty($data['code']) && PricePlan::where('code', $data['code'])->where('id', '!=', $id)->exists()) {
            return $this->error('方案编码已存在', 422);
        }

        $plan->update($data);

        return $this->success($plan, '方案已更新');
    }

    public function destroyPlan($id)
    {
        $plan = PricePlan::find($id);
        if ($plan === null) {
            return $this->notFound('方案不存在');
        }

        $plan->delete();

        return $this->success(null, '方案已删除');
    }

    // ------------------------------------------------------------------
    // 客户特价 CRUD
    // ------------------------------------------------------------------

    /** 客户特价列表：可按客户/商品/方案/状态/期间筛选 */
    public function index(Request $request)
    {
        $query = CustomerSpecialPrice::query()
            ->with(['customer:id,code,name', 'product:id,code,name,spec,price_small', 'plan:id,name,code'])
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->customer_id))
            ->when($request->filled('customer_keyword'), function ($q) use ($request) {
                $kw = '%'.$request->customer_keyword.'%';
                $q->whereIn('customer_id', DB::table('customers')->where('name', 'like', $kw)->orWhere('code', 'like', $kw)->pluck('id'));
            })
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->product_id))
            ->when($request->filled('product_keyword'), function ($q) use ($request) {
                $kw = '%'.$request->product_keyword.'%';
                $q->whereIn('product_id', DB::table('products')->where('name', 'like', $kw)->orWhere('code', 'like', $kw)->pluck('id'));
            })
            ->when($request->filled('price_plan_id'), fn ($q) => $q->where('price_plan_id', $request->price_plan_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')));

        $total = (clone $query)->count();
        $page = max(1, $request->integer('page', 1));
        $pageSize = min(100, max(10, $request->integer('page_size', 20)));
        $list = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        return $this->success([
            'list' => $list->items(), 'total' => $total, 'page' => $page, 'page_size' => $pageSize,
        ]);
    }

    /** 批量保存客户特价（upsert 客户×商品），支持按方案关联 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'price_plan_id' => 'nullable|integer|exists:price_plans,id',
            'items' => 'required|array|min:1',
            'items.*.customer_id' => 'required|integer|exists:customers,id',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.start_date' => 'nullable|date',
            'items.*.end_date' => 'nullable|date|after_or_equal:items.*.start_date',
            'items.*.remark' => 'nullable|string|max:255',
        ]);

        $saved = 0;
        DB::transaction(function () use ($data, &$saved) {
            foreach ($data['items'] as $it) {
                CustomerSpecialPrice::updateOrCreate(
                    ['customer_id' => $it['customer_id'], 'product_id' => $it['product_id']],
                    [
                        'price_plan_id' => $data['price_plan_id'] ?? null,
                        'price' => round((float) $it['price'], 2),
                        'start_date' => $it['start_date'] ?? null,
                        'end_date' => $it['end_date'] ?? null,
                        'remark' => $it['remark'] ?? null,
                        'status' => true,
                    ]
                );
                $saved++;
            }
        });

        return $this->success(['saved' => $saved], "已保存 {$saved} 条客户特价");
    }

    public function update(Request $request, $id)
    {
        $sp = CustomerSpecialPrice::find($id);
        if ($sp === null) {
            return $this->notFound('特价不存在');
        }

        $data = $request->validate([
            'price' => 'sometimes|required|numeric|min:0',
            'price_plan_id' => 'nullable|integer|exists:price_plans,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'nullable|boolean',
            'remark' => 'nullable|string|max:255',
        ]);

        if (array_key_exists('price', $data)) {
            $data['price'] = round((float) $data['price'], 2);
        }
        $sp->update($data);

        return $this->success($sp, '特价已更新');
    }

    public function destroy($id)
    {
        $sp = CustomerSpecialPrice::find($id);
        if ($sp === null) {
            return $this->notFound('特价不存在');
        }

        $sp->delete();

        return $this->success(null, '特价已删除');
    }

    // ------------------------------------------------------------------
    // 有效售价解析（E4 口径：特价 > 等级价 > 默认价）
    // ------------------------------------------------------------------

    /**
     * 跨 客户×商品 解析有效售价，输出与 E4 resolveCustomerPrices() 同形：
     *  standard_price / level_price / special_price / effective_price / price_source / rule_hit。
     * 前端据此复用 ReportTable.vue 的 price_tag 列 + rule_hit 整行高亮。
     */
    public function resolve(Request $request)
    {
        $query = DB::table('customers as c')
            ->crossJoin('products as p')
            ->leftJoin('customer_levels as cl', 'cl.id', '=', 'c.level_id')
            ->leftJoin('product_level_prices as plp', function ($join) {
                $join->on('plp.product_id', '=', 'p.id')
                    ->on('plp.level_id', '=', 'c.level_id');
            })
            // 生效窗口必须与 PriceService::resolve 一致，否则已过期的特价在「价格解析」里
            // 仍显示为命中特价，而下单实际取的是等级价——预览与成交口径打架。
            ->leftJoin('customer_special_price as csp', function ($join) {
                $today = now()->toDateString();
                $join->on('csp.customer_id', '=', 'c.id')
                    ->on('csp.product_id', '=', 'p.id')
                    ->where('csp.status', true)
                    ->where(function ($w) use ($today) {
                        $w->whereNull('csp.start_date')->orWhere('csp.start_date', '<=', $today);
                    })
                    ->where(function ($w) use ($today) {
                        $w->whereNull('csp.end_date')->orWhere('csp.end_date', '>=', $today);
                    });
            })
            ->where('c.is_active', 1)
            ->where('p.is_active', 1);

        if ($request->filled('customer_ids')) {
            $query->whereIn('c.id', (array) $request->input('customer_ids'));
        }
        if ($request->filled('product_ids')) {
            $query->whereIn('p.id', (array) $request->input('product_ids'));
        }
        if ($request->filled('customer_keyword')) {
            $kw = '%'.$request->customer_keyword.'%';
            $query->where(function ($q) use ($kw) {
                $q->where('c.name', 'like', $kw)->orWhere('c.code', 'like', $kw);
            });
        }
        if ($request->filled('product_keyword')) {
            $kw = '%'.$request->product_keyword.'%';
            $query->where(function ($q) use ($kw) {
                $q->where('p.name', 'like', $kw)->orWhere('p.code', 'like', $kw);
            });
        }

        $rows = $query->select([
            'c.id as customer_id',
            'c.code as customer_code',
            'c.name as customer_name',
            DB::raw('COALESCE(cl.name, "-") as level_name'),
            'p.id as product_id',
            'p.code as product_code',
            'p.name as product_name',
            'p.spec',
            'p.price_unit_small as unit',
            DB::raw('p.price_small as standard_price'),
            DB::raw('CASE WHEN cl.id IS NOT NULL THEN COALESCE(plp.price, ROUND(p.price_small * 1.0 * cl.default_discount / 10, 2)) ELSE 0 END as level_price'),
            DB::raw('COALESCE(csp.price, 0) as special_price'),
        ])
            ->orderBy('c.code')
            ->orderBy('p.code')
            ->limit(500)
            ->get();

        $list = $rows->map(function ($r) {
            $standard = (float) $r->standard_price;
            $level = (float) $r->level_price;
            $special = (float) $r->special_price;

            if ($special > 0) {
                $effective = $special;
                $source = '特价';
                $hit = true;
            } elseif ($level > 0) {
                $effective = $level;
                $source = '等级价';
                $hit = true;
            } else {
                $effective = $standard;
                $source = '默认价';
                $hit = false;
            }

            return [
                'customer_id' => (int) $r->customer_id,
                'customer_code' => $r->customer_code,
                'customer_name' => $r->customer_name,
                'level_name' => $r->level_name,
                'product_id' => (int) $r->product_id,
                'product_code' => $r->product_code,
                'product_name' => $r->product_name,
                'spec' => $r->spec,
                'unit' => $r->unit,
                'standard_price' => round($standard, 2),
                'level_price' => round($level, 2),
                'special_price' => round($special, 2),
                'effective_price' => round($effective, 2),
                'price_source' => $source,
                'rule_hit' => $hit,
            ];
        });

        return $this->success([
            'list' => $list,
            'columns' => $this->resolveColumns(),
        ]);
    }

    /** 与 E4 customer_special_price 列定义同构（price_tag + rule_hit） */
    private function resolveColumns(): array
    {
        return [
            ['prop' => 'customer_code', 'label' => '客户编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
            ['prop' => 'customer_name', 'label' => '客户名称', 'width' => 160, 'align' => 'left', 'type' => 'text', 'bold' => true],
            ['prop' => 'level_name', 'label' => '客户等级', 'width' => 100, 'align' => 'center', 'type' => 'text'],
            ['prop' => 'product_code', 'label' => '商品编码', 'width' => 110, 'align' => 'left', 'type' => 'text'],
            ['prop' => 'product_name', 'label' => '商品名称', 'width' => 180, 'align' => 'left', 'type' => 'text'],
            ['prop' => 'standard_price', 'label' => '默认价', 'width' => 100, 'align' => 'right', 'type' => 'money'],
            ['prop' => 'level_price', 'label' => '等级价', 'width' => 100, 'align' => 'right', 'type' => 'money'],
            ['prop' => 'special_price', 'label' => '特价', 'width' => 100, 'align' => 'right', 'type' => 'money'],
            ['prop' => 'effective_price', 'label' => '有效价', 'width' => 110, 'align' => 'right', 'type' => 'money', 'bold' => true],
            ['prop' => 'price_source', 'label' => '价格来源', 'width' => 100, 'align' => 'center', 'type' => 'price_tag', 'hit_type' => 'rule_hit'],
        ];
    }

    // ------------------------------------------------------------------
    // 校验辅助
    // ------------------------------------------------------------------

    private function validatePlan(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:30',
            'type' => 'required|in:special_price,level_price,default_price',
            'discount' => 'nullable|numeric|min:0.1|max:9.9',
            'level_id' => 'nullable|integer|exists:customer_levels,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'nullable|boolean',
            'remark' => 'nullable|string|max:255',
        ]);
    }

    /** 筛选项数据源：客户/商品/等级下拉 */
    public function options()
    {
        $levels = CustomerLevel::where('status', true)->orderBy('sort')->get(['id', 'name', 'code']);

        return $this->success(['levels' => $levels]);
    }
}
