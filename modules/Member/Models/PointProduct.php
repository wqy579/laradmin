<?php

namespace Modules\Member\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PointProduct extends Model
{
    protected $table = 'point_products';

    protected $fillable = [
        'product_no', 'name', 'image', 'spec', 'points', 'stock', 'exchanged',
        'limit_per_person', 'status', 'sort', 'remark',
    ];

    protected $casts = [
        'points' => 'integer',
        'stock' => 'integer',
        'exchanged' => 'integer',
        'limit_per_person' => 'integer',
        'status' => 'integer',
        'sort' => 'integer',
    ];

    const STATUS_ON = 1;
    const STATUS_OFF = 0;

    public function orders(): HasMany
    {
        return $this->hasMany(PointOrder::class, 'product_id');
    }
}
