<?php

namespace Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 资产卡片（AssetCard）
 *
 * 台账卡片视图与列表视图共用本表同一数据源，净值 = 原值 − 累计折旧，
 * D3 阶段未计提折旧，净值与购置单价一致。
 */
class AssetCard extends Model
{
    protected $table = 'asset_card';

    protected $fillable = [
        'asset_no', 'purchase_id', 'asset_name', 'spec', 'original_value',
        'use_months', 'residual_rate', 'dep_method', 'accumulated_dep', 'net_value',
        'department_id', 'department_name', 'user_id', 'user_name', 'status', 'acquire_date',
    ];

    protected $casts = [
        'purchase_id' => 'integer',
        'original_value' => 'decimal:2',
        'use_months' => 'integer',
        'residual_rate' => 'decimal:2',
        'accumulated_dep' => 'decimal:2',
        'net_value' => 'decimal:2',
        'department_id' => 'integer',
        'user_id' => 'integer',
        'acquire_date' => 'date',
    ];

    const STATUS_INUSE = 'inuse';

    const STATUS_IDLE = 'idle';

    const STATUS_DISPOSED = 'disposed';

    const STATUS_SOLD = 'sold';

    public static function statusLabel(string $status): string
    {
        return [
            self::STATUS_INUSE => '在用',
            self::STATUS_IDLE => '闲置',
            self::STATUS_DISPOSED => '已处置',
            self::STATUS_SOLD => '已售',
        ][$status] ?? $status;
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(AssetPurchase::class, 'purchase_id');
    }
}
