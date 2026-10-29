<?php

namespace Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 资产折旧记录（AssetDepreciation）
 *
 * 一条记录 = 一张卡片 × 一个期间（month）的计提结果。
 * month 上同 asset_card_id 唯一，用于期间锁定：已计提月份不可重复计提。
 */
class AssetDepreciation extends Model
{
    protected $table = 'asset_depreciation';

    protected $fillable = [
        'batch_no', 'month', 'asset_card_id', 'asset_no', 'asset_name',
        'original_value', 'residual_rate', 'month_amount',
        'accumulated_dep', 'net_value',
        'department_id', 'department_name', 'voucher_id', 'created_by',
    ];

    protected $casts = [
        'asset_card_id' => 'integer',
        'original_value' => 'decimal:2',
        'residual_rate' => 'decimal:2',
        'month_amount' => 'decimal:2',
        'accumulated_dep' => 'decimal:2',
        'net_value' => 'decimal:2',
        'department_id' => 'integer',
        'voucher_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(AssetCard::class, 'asset_card_id');
    }
}
