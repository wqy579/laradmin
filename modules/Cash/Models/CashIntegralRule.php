<?php

namespace Modules\Cash\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 积分规则（CashIntegralRule）——D 域积分规则 D5。
 *
 * 多行规则列表形态：一条规则一行，rule_name 唯一。
 * D6 核销预留（本批不写不读）：抵扣比例 / 抵扣门槛 / 单笔抵扣上限等
 * deduct_ratio、threshold、limit 类字段与计算均属 D6 范围。
 */
class CashIntegralRule extends Model
{
    protected $table = 'cash_integral_rule';

    protected $fillable = [
        'rule_name', 'ratio', 'effective_date', 'status', 'remark', 'created_by',
    ];

    protected $casts = [
        'ratio' => 'decimal:4',
        'effective_date' => 'date',
        'status' => 'integer',
        'created_by' => 'integer',
    ];

    const STATUS_DISABLED = 0;

    const STATUS_ENABLED = 1;

    public static function statusLabel(int $status): string
    {
        return $status === self::STATUS_ENABLED ? '启用' : '停用';
    }
}
