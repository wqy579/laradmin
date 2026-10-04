<?php

namespace Modules\Order\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Promotion;

/**
 * 促销算价引擎。
 *
 * 输入：客户ID + 订单商品行 [{product_id, qty(最小单位数量), price(最小单位单价)}]。
 * 输出：商品行价改、赠品行、满减减免、命中的促销列表与优惠总额。
 *
 * 规则（对齐需求第七节）：
 *  - 只匹配 status 非 draft/disabled 且在 start_time~end_time 内、且适用该客户的促销；
 *  - 同一商品命中多个折扣/特价促销时，取 priority 最大者；
 *  - 满减默认不与折扣叠加，除非该满减 allow_stack=1；买赠可与其它叠加；
 *  - 数量按最小单位计，单价按最小单位 price_small。
 */
class PromotionService
{
    /** 当前对某客户生效的促销（时间窗内 + 适用客户），按优先级 desc */
    public function activePromotions(?int $customerId): Collection
    {
        $now = now();

        return Promotion::with(['items', 'tiers'])
            ->whereNotIn('status', ['draft', 'disabled'])
            ->where('start_time', '<=', $now)
            ->where('end_time', '>=', $now)
            ->orderByDesc('priority')
            ->get()
            ->filter(function (Promotion $p) use ($customerId) {
                return $this->appliesToCustomer($p, $customerId);
            })
            ->values();
    }

    private function appliesToCustomer(Promotion $p, ?int $customerId): bool
    {
        if ($p->customer_scope === 'all') {
            return true;
        }
        if (!$customerId) {
            return false;
        }
        if ($p->customer_scope === 'specified') {
            return in_array($customerId, (array) $p->customer_ids, false);
        }
        if ($p->customer_scope === 'level') {
            $level = (int) DB::table('customers')->where('id', $customerId)->value('level');
            return in_array($level, array_map('intval', (array) $p->customer_levels), false);
        }

        return false;
    }

    /**
     * 计算订单促销优惠。
     *
     * @param  array  $items  [{product_id, qty, price}]
     */
    public function calculate(?int $customerId, array $items): array
    {
        $promotions = $this->activePromotions($customerId);

        // 原始总额
        $originalTotal = 0.0;
        $qtyByProduct = [];
        foreach ($items as $it) {
            $qty = (int) ($it['qty'] ?? 0);
            $price = (float) ($it['price'] ?? 0);
            $originalTotal += $qty * $price;
            $pid = (int) $it['product_id'];
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + $qty;
        }

        $itemOverrides = [];   // product_id => ['promo_price','label','promotion_id']
        $giftLines = [];       // 赠品行
        $applied = [];         // 命中的促销
        $discountTotal = 0.0;
        $hadProductPromo = false;

        // 1) 商品级促销：限时折扣 / 特价（按优先级，同商品高优先级覆盖）
        foreach ($promotions as $p) {
            if (!in_array($p->type, [Promotion::TYPE_DISCOUNT, Promotion::TYPE_SPECIAL], true)) {
                continue;
            }
            foreach ($p->items as $pi) {
                $pid = (int) $pi->product_id;
                if (!isset($qtyByProduct[$pid])) {
                    continue;
                }
                // 已被更高优先级促销占了该商品（promotions 按 priority desc，先到者优先）
                if (isset($itemOverrides[$pid])) {
                    continue;
                }
                $orig = (float) ($pi->original_price ?: 0);
                if ($p->type === Promotion::TYPE_SPECIAL) {
                    $promoPrice = (float) $pi->special_price;
                    $label = '特价';
                } else {
                    $promoPrice = round($orig * ((float) $pi->discount_rate / 10), 2);
                    $label = ((float) $pi->discount_rate).'折';
                }
                $itemOverrides[$pid] = [
                    'product_id' => $pid,
                    'promo_price' => $promoPrice,
                    'original_price' => $orig,
                    'label' => $label,
                    'promotion_id' => $p->id,
                    'promotion_name' => $p->name,
                ];
                $hadProductPromo = true;
            }
        }

        // 商品级优惠金额
        $productDiscount = 0.0;
        foreach ($itemOverrides as $pid => $ov) {
            $qty = $qtyByProduct[$pid] ?? 0;
            $productDiscount += ((float) $ov['original_price'] - (float) $ov['promo_price']) * $qty;
        }
        $productDiscount = round($productDiscount, 2);

        // 2) 买赠
        foreach ($promotions as $p) {
            if ($p->type !== Promotion::TYPE_BUY_GIFT) {
                continue;
            }
            foreach ($p->items as $pi) {
                $buyPid = (int) $pi->product_id;
                $need = (int) ($pi->buy_qty ?? 0);
                $have = $qtyByProduct[$buyPid] ?? 0;
                if ($need > 0 && $have >= $need) {
                    $giftPid = (int) ($pi->gift_product_id ?: $buyPid);
                    $giftLines[] = [
                        'product_id' => $giftPid,
                        'qty' => (int) ($pi->gift_qty ?? 1),
                        'promotion_id' => $p->id,
                        'promotion_name' => $p->name,
                    ];
                    $applied[$p->id] = ['promotion_id' => $p->id, 'name' => $p->name, 'type' => $p->type, 'amount' => 0.0];
                }
            }
        }

        // 3) 满减：默认不叠加商品折扣，除非 allow_stack
        foreach ($promotions as $p) {
            if ($p->type !== Promotion::TYPE_FULL_REDUCTION) {
                continue;
            }
            if ($hadProductPromo && !$p->allow_stack) {
                continue; // 满减不与折扣叠加
            }
            $base = $hadProductPromo ? ($originalTotal - $productDiscount) : $originalTotal;
            $best = null;
            foreach ($p->tiers->sortByDesc('threshold_amount') as $t) {
                if ($base >= (float) $t->threshold_amount) {
                    $best = (float) $t->discount_amount;
                    break; // 已按门槛 desc，取最高档
                }
            }
            if ($best !== null && $best > 0) {
                $discountTotal += $best;
                $applied[$p->id] = ['promotion_id' => $p->id, 'name' => $p->name, 'type' => $p->type, 'amount' => $best];
            }
        }

        // 商品级优惠计入 applied
        foreach ($itemOverrides as $ov) {
            $pid = $ov['product_id'];
            $qty = $qtyByProduct[$pid] ?? 0;
            $amt = round(((float) $ov['original_price'] - (float) $ov['promo_price']) * $qty, 2);
            if ($amt != 0.0) {
                $id = $ov['promotion_id'];
                $applied[$id] = [
                    'promotion_id' => $id,
                    'name' => $ov['promotion_name'],
                    'type' => in_array($id, array_keys($applied)) ? ($applied[$id]['type'] ?? 'discount') : 'discount',
                    'amount' => ($applied[$id]['amount'] ?? 0) + $amt,
                ];
            }
        }

        $discountTotal = round($discountTotal + $productDiscount, 2);

        return [
            'original_total' => round($originalTotal, 2),
            'discount_total' => $discountTotal,
            'final_total' => round(max(0, $originalTotal - $discountTotal), 2),
            'item_overrides' => array_values($itemOverrides),
            'gift_lines' => $giftLines,
            'applied' => array_values($applied),
        ];
    }
}
