<?php

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 上交货款（配送员→出纳）。按收款方式分类上交，出纳确认后资金从在途转公司。
 */
class DeliveryRemit extends Model
{
    protected $table = 'delivery_remit';

    protected $fillable = [
        'remit_no', 'remit_date', 'delivery_person_id', 'delivery_person_name', 'employee_id',
        'cash_amount', 'wechat_amount', 'alipay_amount', 'bank_amount', 'total_amount',
        'status', 'remark', 'confirmed_by', 'confirmed_at',
    ];

    protected $casts = [
        'remit_date' => 'date',
        'cash_amount' => 'decimal:2',
        'wechat_amount' => 'decimal:2',
        'alipay_amount' => 'decimal:2',
        'bank_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'confirmed_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';     // 待上交
    const STATUS_REMITTED = 'remitted';   // 已上交
    const STATUS_CONFIRMED = 'confirmed'; // 已确认

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryRemitItem::class, 'remit_id');
    }
}
