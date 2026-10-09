<?php

namespace Modules\Stock\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $table = 'warehouses';

    protected $fillable = ['code', 'name', 'address', 'contact', 'phone', 'is_active', 'type', 'vehicle_id'];

    protected $casts = ['is_active' => 'boolean'];

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class, 'warehouse_id');
    }

    /**
     * 关联车辆（type='vehicle' 时）。
     */
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }
}
