<?php

namespace Modules\Cash\Http\Requests;

use App\Http\Requests\BaseFormRequest;

class CashHandoverRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store' => [
                'handover_date' => 'required|date',
                'salesman_id' => 'nullable|integer',
                'salesman_name' => 'nullable|string|max:60',
                'account_id' => 'required|integer|exists:cash_account,id',
                'amount' => 'required|numeric|min:0.01',
                'remark' => 'nullable|string|max:255',
                'items' => 'required|array|min:1',
                'items.*.sales_order_id' => 'required|integer',
                'items.*.handover_amount' => 'required|numeric|min:0',
            ],
            'batchConfirm' => [
                'ids' => 'required|array|min:1',
                'ids.*' => 'integer',
            ],
            default => [],
        };
    }

    public function messages(): array
    {
        return [
            'handover_date.required' => '请选择上交日期',
            'handover_date.date' => '上交日期格式不正确',
            'account_id.required' => '请选择收款账户',
            'account_id.exists' => '收款账户不存在',
            'amount.required' => '请输入上交金额',
            'amount.numeric' => '上交金额必须为数字',
            'amount.min' => '上交金额必须大于 0',
            'items.required' => '请至少关联一张订单',
            'items.min' => '请至少关联一张订单',
            'items.*.sales_order_id.required' => '明细缺少订单',
            'items.*.handover_amount.required' => '请填写明细的本次上交金额',
            'items.*.handover_amount.numeric' => '本次上交金额必须为数字',
            'ids.required' => '请选择要确认的上交款单',
        ];
    }
}
