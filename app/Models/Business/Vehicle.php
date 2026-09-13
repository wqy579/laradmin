<?php

namespace App\Models\Business;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    protected $table = 'vehicles';
    protected $fillable = ['plate_no', 'driver_name', 'driver_phone', 'vehicle_type', 'load_capacity', 'is_active', 'remark'];
    protected $casts = ['is_active' => 'boolean'];
}
