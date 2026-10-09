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
        'status', 'exception_type', 'exception_remark', 'cancel_reason',
        'return_id',
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

    const STATUS_CANCELLED = 'cancelled';   // 已取消（取消后退货入库）

    const EXCEPTION_REJECT = 'reject';         // 客户拒收

    const EXCEPTION_DAMAGED = 'damaged';       // 商品破损

    const EXCEPTION_ADDRESS = 'address';       // 地址错误

    const EXCEPTION_UNREACHABLE = 'unreachable'; // 联系不上客户

    const EXCEPTION_OTHER = 'other';           // 其他

    /** 允许登记的异常类型 */
    public const EXCEPTION_TYPES = [
        self::EXCEPTION_REJECT,
        self::EXCEPTION_DAMAGED,
        self::EXCEPTION_ADDRESS,
        self::EXCEPTION_UNREACHABLE,
        self::EXCEPTION_OTHER,
    ];

    /** 允许取消的任务状态。
     *
     * pending / delivering：货未交付到客户手中，已装车出库的库存需回补。
     * exception：文档 §5.5.4 的异常出口——拒收/破损等异常处理后可取消任务，
     *   否则 exception 既不可推进也不可取消，任务与订单会被永久卡死。
     *
     * delivered / paid 不可取消：货已到客户手中且已收款，应走销售退货流程。
     */
    public const CANCELLABLE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_DELIVERING,
        self::STATUS_EXCEPTION,
    ];

    /** 终态：不再推进，也不允许取消。 */
    public const TERMINAL_STATUSES = [
        self::STATUS_DELIVERED,
        self::STATUS_PAID,
        self::STATUS_CANCELLED,
    ];

    /**
     * 装车单关联。
     *
     * 不可命名为 load()：与 Eloquent 内核 Model::load($relations) 签名冲突，
     * 类加载即 fatal（DeliveryTask::find/create 全部抛 Declaration ... must be compatible）。
     */
    public function deliveryLoad(): BelongsTo
    {
        return $this->belongsTo(DeliveryLoad::class, 'load_id');
    }
}
