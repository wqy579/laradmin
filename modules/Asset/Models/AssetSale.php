<?php

namespace Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 资产变卖单（AssetSale）
 *
 * 一条记录 = 一张卡片的变卖，保存后卡片状态置为已售（sold）。
 * 损益 = 变卖价 − 净值：正=盈利（营业外收入），负=亏损（营业外支出）。
 */
class AssetSale extends Model
{
    protected $table = 'asset_sale';

    protected $fillable = [
        'sale_no', 'asset_card_id', 'asset_no', 'asset_name', 'sale_date',
        'sale_amount', 'net_value', 'accumulated_dep', 'original_value',
        'gain_loss', 'closing_fee', 'buyer', 'handler', 'remark',
        'voucher_id', 'created_by',
    ];

    protected $casts = [
        'asset_card_id' => 'integer',
        'sale_date' => 'date',
        'sale_amount' => 'decimal:2',
        'net_value' => 'decimal:2',
        'accumulated_dep' => 'decimal:2',
        'original_value' => 'decimal:2',
        'gain_loss' => 'decimal:2',
        'closing_fee' => 'decimal:2',
        'voucher_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(AssetCard::class, 'asset_card_id');
    }
}
