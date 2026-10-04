<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionTier extends Model
{
    protected $table = 'promotion_tiers';

    public $timestamps = false;

    protected $fillable = ['promotion_id', 'threshold_amount', 'discount_amount', 'sort'];
}
