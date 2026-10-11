<?php

namespace Modules\Member\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 积分兑换订单。
 *
 * 状态机：0待审核 → 1已审核待发货 → 2已发货 → 3已完成；
 *         0/1/2 可 → 4已驳回 / 5已取消。
 *
 * 核心风险点：只有「已审核」才真正扣减积分与库存，
 * 取消/驳回必须退回——重复扣减是积分业务最常见的资金事故。
 */
class PointOrder extends Model
{
    protected $table = 'point_orders';

    protected $fillable = [
        'order_no', 'member_id', 'member_no', 'member_name',
        'product_id', 'product_name', 'product_image', 'product_spec',
        'points_unit', 'quantity', 'total_points',
        'contact_phone', 'shipping_address',
        'status', 'audit_time', 'auditor_id', 'auditor_name',
        'ship_time', 'ship_no', 'ship_by', 'complete_time',
        'reject_reason', 'remark',
    ];

    protected $casts = [
        'points_unit' => 'integer',
        'quantity' => 'integer',
        'total_points' => 'integer',
        'status' => 'integer',
        'audit_time' => 'datetime',
        'ship_time' => 'datetime',
        'complete_time' => 'datetime',
    ];

    const STATUS_PENDING = 0;
    const STATUS_APPROVED = 1;
    const STATUS_SHIPPED = 2;
    const STATUS_COMPLETED = 3;
    const STATUS_REJECTED = 4;
    const STATUS_CANCELLED = 5;

    /** 已终态，不可再流转 */
    public static function isFinal(int $status): bool
    {
        return in_array($status, [
            self::STATUS_COMPLETED, self::STATUS_REJECTED, self::STATUS_CANCELLED,
        ], true);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(PointProduct::class, 'product_id');
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(\Modules\Auth\Models\User::class, 'auditor_id');
    }

    public static function statusLabel(int $status): string
    {
        return [
            self::STATUS_PENDING => '待审核',
            self::STATUS_APPROVED => '已审核待发货',
            self::STATUS_SHIPPED => '已发货',
            self::STATUS_COMPLETED => '已完成',
            self::STATUS_REJECTED => '已驳回',
            self::STATUS_CANCELLED => '已取消',
        ][$status] ?? '未知';
    }
}
