<?php

namespace Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 提成方案。
 *
 * 五种 calc_type 共用一张表，未启用的方式对应字段留空：
 *   sale_rate / profit_rate → rate（百分比）
 *   fixed_per_unit          → unit_base + unit_amount
 *   tiered                  → tier_data（JSON 阶梯）
 *   product_specific        → product_rules（JSON 单品规则）
 * 落库前由控制器把比例/阶梯等结构化数据收敛进对应字段。
 */
class CommissionRule extends Model
{
    protected $table = 'perf_commission_rules';

    protected $fillable = [
        'code', 'name', 'calc_type',
        'apply_employees', 'apply_customer_levels',
        'product_scope_type', 'product_scope_ids',
        'rate', 'unit_base', 'unit_amount',
        'tier_data', 'product_rules',
        'base_on_payment', 'deduct_return', 'exclude_baddebt',
        'cap_amount', 'floor_amount', 'allow_stack',
        'start_date', 'end_date', 'status', 'remark',
    ];

    protected $casts = [
        'apply_employees' => 'array',
        'apply_customer_levels' => 'array',
        'product_scope_ids' => 'array',
        'tier_data' => 'array',
        'product_rules' => 'array',
        'rate' => 'decimal:4',
        'unit_amount' => 'decimal:4',
        'cap_amount' => 'decimal:2',
        'floor_amount' => 'decimal:2',
        'base_on_payment' => 'boolean',
        'deduct_return' => 'boolean',
        'exclude_baddebt' => 'boolean',
        'allow_stack' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function commissionOrders(): HasMany
    {
        return $this->hasMany(CommissionOrder::class, 'rule_id');
    }
}
