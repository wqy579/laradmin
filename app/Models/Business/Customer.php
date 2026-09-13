<?php

namespace App\Models\Business;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $table = 'customers';
    protected $fillable = [
        'code', 'name', 'category', 'contact', 'phone',
        'address', 'route', 'credit_limit', 'balance', 'level',
        'is_active', 'image', 'latitude', 'longitude',
        'mall_status', 'is_located', 'route_id', 'remark',
    ];
    protected $casts = ['is_active' => 'boolean', 'is_located' => 'boolean'];
    protected $appends = ['route_label'];

    public function belongRoute(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'route_id');
    }

    public function getRouteLabelAttribute(): ?string
    {
        $name = $this->relationLoaded('belongRoute') ? $this->belongRoute?->name : null;
        return $name ?: ($this->attributes['route'] ?? null);
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'customer_id');
    }
}
