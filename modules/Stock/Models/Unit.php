<?php

namespace Modules\Stock\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $table = 'units';

    protected $fillable = ['name', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
