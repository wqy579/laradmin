<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Route extends Model
{
    protected $table = 'routes';
    protected $fillable = ['code', 'name', 'area', 'employee_id', 'sort_order', 'is_active', 'remark'];
    protected $casts = ['is_active' => 'boolean'];
    protected $appends = ['customer_count'];

    public function getCustomerCountAttribute()
    {
        return $this->customers?->count() ?? 0;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(\Modules\Auth\Models\User::class, 'employee_id');
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'route_customers', 'route_id', 'customer_id')
            ->withPivot('visit_order', 'visit_frequency')
            ->withTimestamps();
    }
}
