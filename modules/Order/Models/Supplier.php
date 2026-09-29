<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $table = 'suppliers';

    protected $fillable = ['code', 'name', 'contact', 'phone', 'address', 'bank_name', 'bank_account', 'balance', 'is_active', 'remark'];

    protected $casts = ['is_active' => 'boolean'];
}
