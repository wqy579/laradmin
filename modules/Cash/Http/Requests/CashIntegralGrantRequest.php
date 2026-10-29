<?php

namespace Modules\Cash\Http\Requests;

use App\Http\Requests\BaseFormRequest;

/**
 * 积分发放单表单校验（D 域 D5）。
 *
 * items 为左右分栏录入页的明细行：至少一行、会员必填且存在、积分为正整数。
 */
class CashIntegralGrantRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store', 'update' => [
                'grant_date' => 'required|date',
                'grant_type' => 'required|string|in:daily,activity,compensate,other',
                'remark' => 'nullable|string|max:255',
                'items' => 'required|array|min:1',
                'items.*.member_id' => 'required|integer|exists:members,id',
                'items.*.points' => 'required|integer|min:1|max:10000000',
                'items.*.remark' => 'nullable|string|max:255',
            ],
            default => [],
        };
    }

    public function messages(): array
    {
        return [
            'grant_date.required' => '请选择发放日期',
            'grant_type.required' => '请选择发放类型',
            'grant_type.in' => '发放类型不正确',
            'items.required' => '请至少添加一条发放明细',
            'items.min' => '请至少添加一条发放明细',
            'items.*.member_id.required' => '请选择会员',
            'items.*.member_id.exists' => '会员不存在',
            'items.*.points.required' => '请输入发放积分',
            'items.*.points.integer' => '发放积分必须为整数',
            'items.*.points.min' => '发放积分必须大于 0',
        ];
    }
}
