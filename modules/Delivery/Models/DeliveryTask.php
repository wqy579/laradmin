<?php

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 配送任务（配送员配送）。由装车单确认后自动生成，一验货单一任务。
 */
class DeliveryTask extends Model
{
    protected $table = 'delivery_task';

    protected $fillable = [
        'task_no', 'load_id', 'load_no', 'check_id', 'check_no',
        'sales_order_id', 'order_no', 'customer_id', 'customer_name',
        'address', 'phone', 'warehouse_id',
        'delivery_person_id', 'delivery_person_name', 'employee_id',
        'vehicle_id', 'plate_no',
        'total_skus', 'total_qty', 'order_amount', 'paid_amount', 'unpaid_amount',
        'status', 'exception_type', 'exception_remark',
        'started_at', 'delivered_at', 'collected_at', 'remark',
    ];

    protected $casts = [
        'order_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'unpaid_amount' => 'decimal:2',
        'started_at' => 'datetime',
        'delivered_at' => 'datetime',
        'collected_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';       // 待配送
    const STATUS_DELIVERING = 'delivering'; // 配送中
    const STATUS_DELIVERED = 'delivered';   // 已送达
    const STATUS_PAID = 'paid';             // 已收款
    const STATUS_EXCEPTION = 'exception';   // 异常

    const EXCEPTION_REJECT = 'reject';     // 客户拒收
    const EXCEPTION_DAMAGED = 'damaged';   // 商品破损
    const EXCEPTION_ADDRESS = 'address';   // 地址错误

    public function load(): BelongsTo
    {
        return $this->belongsTo(DeliveryLoad::class, 'load_id');
    }
}
