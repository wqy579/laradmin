<?php

namespace Modules\VanSales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 车销上交货款单：业务员把当日车销收取的货款上交出纳。
 *
 * 纯台账型——confirm 时写一条 cash_flows(related_type=VanRemit)，
 * 与 VanSaleOrder::approve 的流水(related_type=VanSaleOrder) 通过 related_type 区分。
 */
class VanRemit extends Model
{
    protected $table = 'van_remit';

    protected $fillable = [
        'remit_no', 'salesman_id', 'salesman_name', 'remit_date',
        'cash_amount', 'wechat_amount', 'alipay_amount', 'bank_amount', 'total_amount',
        'status', 'confirmed_by', 'confirmed_at', 'reject_reason', 'remark',
    ];

    protected $casts = [
        'remit_date' => 'date',
        'cash_amount' => 'float',
        'wechat_amount' => 'float',
        'alipay_amount' => 'float',
        'bank_amount' => 'float',
        'total_amount' => 'float',
        'confirmed_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';

    const STATUS_CONFIRMED = 'confirmed';

    const STATUS_REJECTED = 'rejected';

    public function items(): HasMany
    {
        return $this->hasMany(VanRemitItem::class, 'remit_id');
    }
}
