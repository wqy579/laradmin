<?php

namespace Modules\Cash\Http\Requests;

use App\Http\Requests\BaseFormRequest;

/**
 * 积分核销单校验（D6）。
 *
 * 核销额是否超过会员可用积分不在这里判（需要行锁读实时余额），
 * 由 CashIntegralService::verifyAudit 在事务内裁决，这里只保证入参形态合法。
 */
class CashIntegralVerifyRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store', 'update' => [
                'verify_date' => 'required|date',
                'member_id' => 'required|integer|exists:members,id',
                'points' => 'required|integer|min:1|max:10000000',
                'verify_type' => 'nullable|string|in:consume,exchange,adjust,other',
                'related_order_no' => 'nullable|string|max:32',
                'remark' => 'nullable|string|max:255',
            ],
            default => [],
        };
    }

    public function messages(): array
    {
        return [
            'verify_date.required' => '请选择核销日期',
            'member_id.required' => '请选择会员',
            'member_id.exists' => '会员不存在',
            'points.required' => '请输入核销积分',
            'points.integer' => '核销积分必须为整数',
            'points.min' => '核销积分必须大于 0',
            'verify_type.in' => '核销类型不正确',
            'related_order_no.max' => '关联单号不能超过 32 个字符',
        ];
    }
}
