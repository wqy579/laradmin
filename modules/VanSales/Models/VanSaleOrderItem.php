<?php

namespace Modules\VanSales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stock\Models\Product;

class VanSaleOrderItem extends Model
{
    protected $table = 'van_sale_order_items';

    protected $fillable = [
        'order_id', 'product_id', 'product_code', 'product_name',
        'spec', 'unit', 'stock_qty', 'sale_qty', 'unit_price', 'price_source',
        'amount', 'remark', 'sort',
    ];

    protected $casts = [
        'stock_qty' => 'integer',
        'sale_qty' => 'integer',
        'unit_price' => 'float',
        'amount' => 'float',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(VanSaleOrder::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
