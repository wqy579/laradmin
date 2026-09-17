<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitLog extends Model
{
    protected $table = 'visit_logs';
    protected $fillable = [
        'visit_no', 'employee_id', 'customer_id', 'route_id',
        'checkin_time', 'checkin_lat', 'checkin_lng', 'checkin_address', 'checkin_photo',
        'checkout_time', 'checkout_lat', 'checkout_lng', 'checkout_photo',
        'visit_duration', 'visit_result', 'remark', 'status', 'created_by',
    ];
    protected $casts = [
        'checkin_time' => 'datetime',
        'checkout_time' => 'datetime',
        'checkin_lat' => 'decimal:7',
        'checkin_lng' => 'decimal:7',
        'checkout_lat' => 'decimal:7',
        'checkout_lng' => 'decimal:7',
        'status' => 'integer',
    ];

    protected $appends = ['employee_name', 'customer_name', 'route_name'];

    public function getEmployeeNameAttribute()
    {
        return $this->employee?->real_name ?? '未知';
    }

    public function getCustomerNameAttribute()
    {
        return $this->customer?->name ?? '未知';
    }

    public function getRouteNameAttribute()
    {
        return $this->route?->name ?? '未知';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(\Modules\Auth\Models\User::class, 'employee_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'route_id');
    }
}
