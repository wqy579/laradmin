<?php

namespace Modules\VanSales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Order\Models\Customer;
use Modules\Stock\Models\Vehicle;
use Modules\Stock\Models\Warehouse;

class VanSaleOrder extends Model
{
    protected $table = 'van_sale_orders';

    protected $fillable = [
        'order_no', 'salesman_id', 'salesman_name', 'customer_id', 'customer_name',
        'vehicle_id', 'vehicle_warehouse_id', 'sale_date', 'total_qty', 'total_amount',
        'paid_amount', 'payment_method', 'status', 'visit_log_id',
        'created_by', 'creator_name', 'approved_at', 'remark',
    ];

    protected $casts = [
        'sale_date' => 'date',
        'total_amount' => 'float',
        'paid_amount' => 'float',
        'approved_at' => 'datetime',
    ];

    const STATUS_DRAFT = 'draft';

    const STATUS_APPROVED = 'approved';

    const STATUS_CANCELLED = 'cancelled';

    const PAYMENT_CASH = 'cash';

    const PAYMENT_WECHAT = 'wechat';

    const PAYMENT_ALIPAY = 'alipay';

    const PAYMENT_CARD = 'card';

    const PAYMENT_CREDIT = 'credit';

    public function items(): HasMany
    {
        return $this->hasMany(VanSaleOrderItem::class, 'order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function vehicleWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'vehicle_warehouse_id');
    }
}
