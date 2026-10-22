<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Stock\Models\Product;

/**
 * 客户订货核对明细（T5.1）
 *
 * declared_qty 申报数量（来自订单行） / suggest_qty 建议或历史数量（可空）
 * check_qty 核对确认数量 / diff_rate 差异率（建议<=0 时为 null）
 */
class PresaleCheckItem extends Model
{
    protected $table = 'presale_check_item';

    protected $fillable = [
        'presale_check_id', 'sales_order_item_id', 'product_id',
        'declared_qty', 'suggest_qty', 'check_qty',
        'diff_rate', 'result', 'remark',
    ];

    protected $casts = [
        'declared_qty' => 'float',
        'suggest_qty' => 'float',
        'check_qty' => 'float',
        'diff_rate' => 'float',
    ];

    public function presaleCheck(): BelongsTo
    {
        return $this->belongsTo(PresaleCheck::class, 'presale_check_id');
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
