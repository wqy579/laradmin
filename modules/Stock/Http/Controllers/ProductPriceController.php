<?php

namespace Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Models\CustomerLevel;
use Modules\Stock\Models\ProductLevelPrice;
use Modules\Stock\Models\ProductPriceHistory;
use Modules\Stock\Services\PriceService;

class ProductPriceController extends Controller
{
    use ResponseTrait;

    public function __construct(private PriceService $priceService) {}

    /** 商品价格宽表：每个商品 + 各等级价格 */
    public function index(Request $request)
    {
        $levels = CustomerLevel::where('status', true)->orderBy('sort')->get();

        $query = DB::table('products')->where('is_active', 1)
            ->when($request->filled('keyword'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$request->keyword}%")->orWhere('code', 'like', "%{$request->keyword}%")))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->category_id));

        $total = (clone $query)->count();
        $page = max(1, $request->integer('page', 1));
        $pageSize = min(100, max(10, $request->integer('page_size', 20)));
        $products = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        // 一次性取出所有等级价
        $pids = collect($products->items())->pluck('id')->all();
        $levelPrices = DB::table('product_level_prices')->whereIn('product_id', $pids)->get()
            ->groupBy('product_id');

        $list = collect($products->items())->map(function ($p) use ($levels, $levelPrices) {
            $row = [
                'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'spec' => $p->spec,
                'unit' => $p->price_unit_small, 'image' => $p->image,
                'cost_price' => (float) $p->cost_price, 'price_small' => (float) $p->price_small,
                'levels' => [],
            ];
            foreach ($levels as $lv) {
                $lp = $levelPrices[$p->id] ?? collect();
                $price = optional($lp->firstWhere('level_id', $lv->id))->price;
                $row['levels'][] = [
                    'level_id' => $lv->id, 'name' => $lv->name, 'code' => $lv->code,
                    'price' => $price !== null ? (float) $price : null,
                    'default_discount' => (float) $lv->default_discount,
                ];
            }

            return $row;
        });

        return $this->success([
            'list' => $list, 'total' => $total, 'page' => $page, 'page_size' => $pageSize,
            'levels' => $levels,
        ]);
    }

    /** 保存单个商品：标准售价 + 各等级价，写历史 */
    public function save(Request $request, $productId)
    {
        $data = $request->validate([
            'price_small' => 'nullable|numeric|min:0',
            'levels' => 'nullable|array',
            'levels.*.level_id' => 'required|integer',
            'levels.*.price' => 'nullable|numeric|min:0',
        ]);
        $admin = auth('admin')->user();
        $opName = $admin?->name ?? ($admin?->username ?? '管理员');

        return DB::transaction(function () use ($productId, $data, $opName) {
            $product = DB::table('products')->where('id', $productId)->first();
            if (! $product) {
                return $this->notFound('商品不存在');
            }
            // 标准售价
            if (isset($data['price_small'])) {
                $old = (float) $product->price_small;
                $new = round((float) $data['price_small'], 2);
                if (abs($old - $new) > 0.001) {
                    DB::table('products')->where('id', $productId)->update(['price_small' => $new]);
                    $this->logHistory($productId, 'standard', '标准售价', $old, $new, 'manual', null, $opName);
                }
            }
            // 各等级价
            foreach (($data['levels'] ?? []) as $lv) {
                $lid = (int) $lv['level_id'];
                $new = isset($lv['price']) && $lv['price'] !== null ? round((float) $lv['price'], 2) : null;
                $row = ProductLevelPrice::where('product_id', $productId)->where('level_id', $lid)->first();
                $old = $row ? (float) $row->price : null;
                if ($new === null) {
                    continue; // 空值不写
                }
                if ($row) {
                    if (abs($old - $new) > 0.001) {
                        $row->update(['price' => $new]);
                        $this->logHistory($productId, "level_{$lid}", CustomerLevel::find($lid)?->name, $old, $new, 'manual', null, $opName);
                    }
                } else {
                    ProductLevelPrice::create(['product_id' => $productId, 'level_id' => $lid, 'price' => $new]);
                    $this->logHistory($productId, "level_{$lid}", CustomerLevel::find($lid)?->name, null, $new, 'manual', null, $opName);
                }
            }

            return $this->success(null, '已保存');
        });
    }

    /** 批量调价 */
    public function batch(Request $request)
    {
        $data = $request->validate([
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'integer',
            'mode' => 'required|in:ratio,amount,set',
            'value' => 'required|numeric',
            'scopes' => 'required|array|min:1', // ['standard', level_id, ...]
        ]);
        $admin = auth('admin')->user();
        $opName = $admin?->name ?? ($admin?->username ?? '管理员');
        $rule = "{$data['mode']}:{$data['value']}";

        return DB::transaction(function () use ($data, $rule, $opName) {
            $products = DB::table('products')->whereIn('id', $data['product_ids'])->get();
            $changed = 0;
            foreach ($products as $p) {
                // 标准售价
                if (in_array('standard', $data['scopes'], true)) {
                    $old = (float) $p->price_small;
                    $new = $this->applyRule($old, $data['mode'], (float) $data['value']);
                    if (abs($old - $new) > 0.001) {
                        DB::table('products')->where('id', $p->id)->update(['price_small' => $new]);
                        $this->logHistory($p->id, 'standard', '标准售价', $old, $new, 'batch', $rule, $opName);
                        $changed++;
                    }
                }
                // 等级价
                $levelScopes = array_filter($data['scopes'], fn ($s) => $s !== 'standard');
                if ($levelScopes) {
                    $rows = ProductLevelPrice::where('product_id', $p->id)->whereIn('level_id', $levelScopes)->get();
                    foreach ($rows as $row) {
                        $old = (float) $row->price;
                        $new = $this->applyRule($old, $data['mode'], (float) $data['value']);
                        if (abs($old - $new) > 0.001) {
                            $row->update(['price' => $new]);
                            $this->logHistory($p->id, "level_{$row->level_id}", CustomerLevel::find($row->level_id)?->name, $old, $new, 'batch', $rule, $opName);
                            $changed++;
                        }
                    }
                }
            }

            return $this->success(['changed' => $changed], "成功调价 {$products->count()} 件商品，更新 {$changed} 个价格");
        });
    }

    private function applyRule(float $old, string $mode, float $value): float
    {
        return match ($mode) {
            'ratio' => round($old * $value, 2),
            'amount' => round(max(0, $old + $value), 2),
            'set' => round(max(0, $value), 2),
        };
    }

    /** 价格历史 */
    public function history(Request $request)
    {
        $query = ProductPriceHistory::query()
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->product_id))
            ->when($request->filled('price_type'), fn ($q) => $q->where('price_type', $request->price_type));
        $total = (clone $query)->count();
        $list = $query->orderByDesc('id')->limit(100)->get();

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /** 订单页取价：传 customer_id + 商品列表，返回每个商品应取价格 */
    public function calculate(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'nullable|integer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.price' => 'nullable|numeric|min:0',
        ]);
        $out = [];
        foreach ($data['items'] as $it) {
            $res = $this->priceService->resolve(
                isset($data['customer_id']) ? (int) $data['customer_id'] : null,
                (int) $it['product_id'],
                (float) ($it['price'] ?? 0)
            );
            $out[] = ['product_id' => (int) $it['product_id'], 'price' => $res['price'], 'source' => $res['source']];
        }

        return $this->success(['list' => $out]);
    }

    private function logHistory(int $productId, string $type, ?string $typeName, ?float $old, ?float $new, string $changeType, ?string $rule, string $opName): void
    {
        ProductPriceHistory::create([
            'product_id' => $productId, 'price_type' => $type, 'price_type_name' => $typeName,
            'old_price' => $old, 'new_price' => $new, 'change_type' => $changeType,
            'batch_rule' => $rule, 'operator_name' => $opName,
        ]);
    }
}
