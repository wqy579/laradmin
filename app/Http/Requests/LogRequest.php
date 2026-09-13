<?php

namespace App\Http\Requests;

class LogRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $rules = [];

        if ($this->isMethod('GET')) {
            $rules = [
                'user_id' => 'nullable|integer|exists:auth_users,id',
                'username' => 'nullable|string|max:50',
                'module' => 'nullable|string|max:50',
                'action' => 'nullable|string|max:100',
                'method' => 'nullable|in:GET,POST,PUT,DELETE,PATCH',
                'status' => 'nullable|in:success,error',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'ip' => 'nullable|ip',
                'page' => 'nullable|integer|min:1',
                'page_size' => 'nullable|integer|min:1|max:100',
            ];
        } elseif ($this->isMethod('POST')) {
            if ($this->routeIs('*.batch-delete')) {
                $rules = [
                    'ids' => 'required|array',
                    'ids.*' => 'required|integer|exists:system_logs,id',
                ];
            }
            if ($this->routeIs('*.clear')) {
                $rules = [
                    'days' => 'nullable|integer|min:1|max:365',
                ];
            }
        } elseif ($this->isMethod('DELETE')) {
            $rules = [
                'id' => 'required|integer|exists:system_logs,id',
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'user_id.exists' => '用户不存在',
            'username.max' => '用户名最多50个字符',
            'module.max' => '模块名最多50个字符',
            'action.max' => '操作名最多100个字符',
            'method.in' => '请求方式必须是 GET、POST、PUT、DELETE 或 PATCH',
            'status.in' => '状态值必须是 success 或 error',
            'start_date.date' => '开始日期格式不正确',
            'end_date.date' => '结束日期格式不正确',
            'end_date.after_or_equal' => '结束日期必须大于或等于开始日期',
            'ip.ip' => 'IP地址格式不正确',
            'page.integer' => '页码必须是整数',
            'page.min' => '页码必须大于0',
            'page_size.integer' => '每页数量必须是整数',
            'page_size.min' => '每页数量必须大于0',
            'page_size.max' => '每页数量不能超过100',
            'ids.required' => '请选择要删除的日志',
            'ids.array' => '日志ID必须是数组',
            'ids.*.required' => '日志ID不能为空',
            'ids.*.integer' => '日志ID必须是整数',
            'ids.*.exists' => '日志不存在',
            'days.integer' => '天数必须是整数',
            'days.min' => '天数必须大于0',
            'days.max' => '天数不能超过365',
            'id.required' => '日志ID不能为空',
            'id.integer' => '日志ID必须是整数',
            'id.exists' => '日志不存在',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->isMethod('GET')) {
            $this->merge([
                'page' => $this->input('page', 1),
                'page_size' => $this->input('page_size', 20),
                'days' => $this->input('days', 30),
            ]);
        }
    }
}
