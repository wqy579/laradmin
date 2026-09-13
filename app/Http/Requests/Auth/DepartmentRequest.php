<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseFormRequest;

class DepartmentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $method = $this->route()->getActionMethod();

        return match ($method) {
            'store' => [
                'name' => 'required|string|max:50',
                'parent_id' => 'nullable|integer',
                'leader' => 'nullable|string|max:50',
                'phone' => 'nullable|string|max:20',
                'sort' => 'nullable|integer|min:0',
                'status' => 'nullable|integer|in:0,1',
            ],
            'update' => [
                'name' => 'nullable|string|max:50',
                'parent_id' => 'nullable|integer',
                'leader' => 'nullable|string|max:50',
                'phone' => 'nullable|string|max:20',
                'sort' => 'nullable|integer|min:0',
                'status' => 'nullable|integer|in:0,1',
            ],
            'batchDelete' => [
                'ids' => 'required|array',
                'ids.*' => 'integer',
            ],
            'batchUpdateStatus' => [
                'ids' => 'required|array',
                'ids.*' => 'integer',
                'status' => 'required|integer|in:0,1',
            ],
            'export' => [
                'ids' => 'nullable|array',
                'ids.*' => 'integer',
            ],
            'import' => [
                'file' => 'required|file|mimes:xlsx,xls',
            ],
            default => [],
        };
    }

    public function messages(): array
    {
        return [
            'name.required' => '请输入部门名称',
            'ids.required' => '请选择数据',
            'status.required' => '请选择状态',
            'status.in' => '状态值不正确',
            'file.required' => '请上传文件',
            'file.mimes' => '文件格式不正确，仅支持 xlsx/xls',
        ];
    }
}
