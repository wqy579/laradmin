<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User as AdminUser;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\Warehouse;

class Assembly extends Model
{
    protected $table = 'assembly_orders';

    protected $fillable = [
        'assembly_no', 'parent_product_id', 'parent_product_name', 'parent_product_code',
        'warehouse_id', 'quantity', 'total_cost', 'unit_cost', 'status',
        'salesman_id', 'assembly_date', 'remark',
        'created_by', 'approved_by', 'approved_at', 'approval_comment',
    ];

    protected $casts = [
        'assembly_date' => 'date',
        'quantity' => 'integer',
        'total_cost' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function parentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AssemblyItem::class, 'assembly_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by');
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'salesman_id');
    }
}
