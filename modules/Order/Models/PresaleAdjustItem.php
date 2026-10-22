<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stock\Models\Product;

/**
 * 调整单明细：一行对应一个销售单明细，original_* 为下单时快照，adjusted_* 为调整后值。
 * diff_amount = adjusted_amount − original_amount，正负号保留（减价为负、加价为正）。
 */
class PresaleAdjustItem extends Model
{
    protected $table = 'presale_adjust_item';

    protected $fillable = [
        'presale_adjust_id', 'sales_order_item_id', 'product_id',
        'original_qty', 'original_price', 'original_amount',
        'adjusted_qty', 'adjusted_price', 'adjusted_amount',
        'diff_amount', 'remark',
    ];

    protected $casts = [
        'original_qty' => 'decimal:3',
        'original_price' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'adjusted_qty' => 'decimal:3',
        'adjusted_price' => 'decimal:2',
        'adjusted_amount' => 'decimal:2',
        'diff_amount' => 'decimal:2',
    ];

    public function adjust(): BelongsTo
    {
        return $this->belongsTo(PresaleAdjust::class, 'presale_adjust_id');
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
