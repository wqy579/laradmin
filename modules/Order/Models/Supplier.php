<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $table = 'suppliers';

    protected $fillable = ['code', 'name', 'contact', 'phone', 'address', 'bank_name', 'bank_account', 'balance', 'is_active', 'remark'];

    protected $casts = ['is_active' => 'boolean'];

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id');
    }
}
