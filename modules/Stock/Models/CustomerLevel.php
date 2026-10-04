<?php

namespace Modules\Stock\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerLevel extends Model
{
    protected $table = 'customer_levels';

    protected $fillable = ['name', 'code', 'default_discount', 'sort', 'is_system', 'status'];

    protected $casts = [
        'default_discount' => 'float',
        'is_system' => 'boolean',
        'status' => 'boolean',
    ];
}
