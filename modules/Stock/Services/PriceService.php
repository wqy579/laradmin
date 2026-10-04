<?php

namespace Modules\Stock\Services;

use Illuminate\Support\Facades\DB;
use Modules\Order\Services\PromotionService;
use Modules\Stock\Models\CustomerLevel;

/**
 * 订单自动取价引擎。
 *
 * 优先级：促销价 > 商品等级单独价 > 等级默认折扣价 > 标准售价(price_small)。
 * 促销部分复用 PromotionService 的限时折扣/特价结果。
 */
class PriceService
{
    public function __construct(private PromotionService $promotions) {}

    /**
     * 计算某客户对某商品（小单位）的应取价格。
     *
     * @return array{price:float, source:string}
     */
    public function resolve(?int $customerId, int $productId, float $standardPrice): array
    {
        // 1) 促销价：用 calculate 看该商品是否被促销覆盖
        if ($customerId) {
            $calc = $this->promotions->calculate($customerId, [[
                'product_id' => $productId, 'qty' => 1, 'price' => $standardPrice,
            ]]);
            foreach ($calc['item_overrides'] ?? [] as $ov) {
                if ((int) $ov['product_id'] === $productId && (float) $ov['promo_price'] > 0) {
                    return ['price' => round((float) $ov['promo_price'], 2), 'source' => '促销价 '.$ov['label']];
                }
            }
        }

        // 2) 客户等级：优先 level_id，为空则回退旧 level 列（值=customer_levels.id）
        $levelId = $this->resolveLevelId($customerId);
        if ($levelId) {
            $level = CustomerLevel::find($levelId);
            if ($level) {
                // 2a) 商品单独等级价
                $lp = DB::table('product_level_prices')
                    ->where('product_id', $productId)->where('level_id', $levelId)->value('price');
                if ($lp !== null && (float) $lp > 0) {
                    return ['price' => round((float) $lp, 2), 'source' => $level->name.'价'];
                }
                // 2b) 等级默认折扣
                if ((float) $level->default_discount > 0) {
                    return ['price' => round($standardPrice * (float) $level->default_discount / 10, 2), 'source' => $level->name.$level->default_discount.'折'];
                }
            }
        }

        return ['price' => round($standardPrice, 2), 'source' => '标准售价'];
    }

    /** 解析客户等级 ID：优先 level_id 外键，回退旧 level 列 */
    private function resolveLevelId(?int $customerId): ?int
    {
        if (! $customerId) {
            return null;
        }
        $row = DB::table('customers')->where('id', $customerId)->first(['level_id', 'level']);
        if (! $row) {
            return null;
        }
        if ($row->level_id) {
            return (int) $row->level_id;
        }
        // 旧数据用 level 列存等级 ID
        if ($row->level) {
            return (int) $row->level;
        }

        return null;
    }
}
