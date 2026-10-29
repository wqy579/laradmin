<?php

namespace Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 固定资产购置单（AssetPurchase）
 *
 * original_value 恒为 数量×单价（由 AssetPurchaseService 重算写入），
 * voucher_id 非空即代表已登记现金支出凭证。
 */
class AssetPurchase extends Model
{
    protected $table = 'asset_purchase';

    protected $fillable = [
        'purchase_no', 'purchase_date', 'asset_name', 'spec', 'quantity', 'unit_price',
        'original_value', 'use_months', 'residual_rate', 'dep_method',
        'department_id', 'department_name', 'user_id', 'user_name',
        'supplier', 'voucher_id', 'remark', 'status', 'created_by',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'original_value' => 'decimal:2',
        'use_months' => 'integer',
        'residual_rate' => 'decimal:2',
        'department_id' => 'integer',
        'user_id' => 'integer',
        'voucher_id' => 'integer',
        'created_by' => 'integer',
    ];

    const STATUS_NORMAL = 'normal';

    const DEP_STRAIGHT = 'straight';

    const DEP_AVERAGE = 'average';

    public static function depMethodLabel(string $method): string
    {
        return $method === self::DEP_AVERAGE ? '平均年限法' : '直线法';
    }

    public function cards(): HasMany
    {
        return $this->hasMany(AssetCard::class, 'purchase_id');
    }
}
