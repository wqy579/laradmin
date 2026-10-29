<?php

namespace Modules\Stock\Models;

use Illuminate\Database\Eloquent\Model;

class PricePlan extends Model
{
    protected $table = 'price_plans';

    protected $fillable = [
        'name', 'code', 'type', 'discount', 'level_id',
        'start_date', 'end_date', 'status', 'remark',
    ];

    protected $casts = [
        'discount' => 'float',
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => 'boolean',
    ];

    /** 价格方案下挂的客户特价明细 */
    public function specialPrices()
    {
        return $this->hasMany(CustomerSpecialPrice::class, 'price_plan_id');
    }

    public function level()
    {
        return $this->belongsTo(CustomerLevel::class, 'level_id');
    }
}
