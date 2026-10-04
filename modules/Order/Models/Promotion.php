<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    protected $table = 'promotions';

    protected $fillable = [
        'promotion_no', 'name', 'type', 'start_time', 'end_time',
        'customer_scope', 'customer_levels', 'customer_ids',
        'priority', 'allow_stack', 'status', 'remark',
        'created_by', 'creator_name',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'customer_levels' => 'array',
        'customer_ids' => 'array',
        'allow_stack' => 'boolean',
    ];

    const TYPE_DISCOUNT = 'discount';

    const TYPE_FULL_REDUCTION = 'full_reduction';
    const TYPE_BUY_GIFT = 'buy_gift';

    const TYPE_SPECIAL = 'special_price';

    public function items(): HasMany
    {
        return $this->hasMany(PromotionItem::class, 'promotion_id');
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(PromotionTier::class, 'promotion_id')->orderBy('sort');
    }

    /** 推导当前展示状态：draft/disabled 优先，否则按时间算 upcoming/active/ended */
    public function derivedStatus(): string
    {
        if ($this->status === 'disabled') {
            return 'disabled';
        }
        if ($this->status === 'draft') {
            return 'draft';
        }
        $now = now();
        if ($now->lt($this->start_time)) {
            return 'upcoming';
        }
        if ($now->gt($this->end_time)) {
            return 'ended';
        }

        return 'active';
    }
}
