<?php

namespace Modules\Business\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $table = 'warehouses';
    protected $fillable = ['code', 'name', 'address', 'contact', 'phone', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class, 'warehouse_id');
    }
}
