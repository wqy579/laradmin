<?php

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 配送收款（配送员收款）。对已送达配送任务收款，支持部分收款。
 */
class DeliveryCollection extends Model
{
    protected $table = 'delivery_collection';

    protected $fillable = [
        'collection_no', 'task_id', 'task_no', 'sales_order_id', 'order_no',
        'customer_id', 'customer_name',
        'delivery_person_id', 'delivery_person_name', 'employee_id',
        'receivable_amount', 'received_amount',
        'payment_method', 'collect_date', 'status', 'remark',
    ];

    protected $casts = [
        'receivable_amount' => 'decimal:2',
        'received_amount' => 'decimal:2',
        'collect_date' => 'date',
    ];

    const STATUS_PENDING = 'pending';   // 待收款

    const STATUS_PARTIAL = 'partial';   // 部分收款

    const STATUS_PAID = 'paid';         // 已收款

    // 收款方式
    const METHOD_CASH = '现金';

    const METHOD_WECHAT = '微信';

    const METHOD_ALIPAY = '支付宝';

    const METHOD_BANK = '银行卡';

    const METHOD_CREDIT = '挂账';

    public function task(): BelongsTo
    {
        return $this->belongsTo(DeliveryTask::class, 'task_id');
    }
}
