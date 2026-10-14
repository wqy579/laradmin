<?php

namespace Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 提成方案（任务书 §八）。
 *
 * type 五种：sales 销售额 / profit 毛利 / piece 固定件数 / ladder 阶梯 / single 单品专属。
 * rule JSON 存比例规则；scope=all|specified + employee_ids 圈定适用业务员。
 * 平铺旧列（calc_type/rate/unit_amount/tier_data/product_rules/三个开关）继续作为
 * 计算器的数据源，控制器读写时与 type/rule/scope/employee_ids 双向同步。
 */
class CommissionRule extends Model
{
    protected $table = 'perf_commission_rules';

    protected $fillable = [
        'name', 'type', 'rule', 'scope', 'employee_ids',
        'start_date', 'end_date', 'status', 'remark',
        // 兼容平铺列（计算器直接读）
        'code', 'calc_type',
        'apply_employees', 'apply_customer_levels',
        'product_scope_type', 'product_scope_ids',
        'rate', 'unit_base', 'unit_amount',
        'tier_data', 'product_rules',
        'base_on_payment', 'deduct_return', 'exclude_baddebt',
        'cap_amount', 'floor_amount', 'allow_stack',
    ];

    protected $casts = [
        'rule' => 'array',
        'employee_ids' => 'array',
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
