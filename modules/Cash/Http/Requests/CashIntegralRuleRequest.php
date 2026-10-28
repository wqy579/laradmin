<?php

namespace Modules\Cash\Http\Requests;

use App\Http\Requests\BaseFormRequest;

/**
 * 积分规则表单校验（D 域 D5）。
 */
class CashIntegralRuleRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store' => $this->fields(unique: true),
            'update' => $this->fields(unique: true, ignoreId: (int) $this->route('id')),
            default => [],
        };
    }

    private function fields(bool $unique, ?int $ignoreId = null): array
    {
        $ruleName = 'required|string|max:50';
        if ($unique) {
            $ruleName .= '|unique:cash_integral_rule,rule_name'.($ignoreId ? ','.$ignoreId : '');
        }

        return [
            'rule_name' => $ruleName,
            'ratio' => 'required|numeric|min:0.0001|max:999999',
            'effective_date' => 'required|date',
            'status' => 'nullable|integer|in:0,1',
            'remark' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'rule_name.required' => '请输入规则名称',
            'rule_name.unique' => '规则名称已存在',
            'ratio.required' => '请输入积分比例',
            'ratio.numeric' => '积分比例必须为数字',
            'ratio.min' => '积分比例必须大于 0',
            'effective_date.required' => '请选择生效日期',
            'effective_date.date' => '生效日期格式不正确',
        ];
    }
}
