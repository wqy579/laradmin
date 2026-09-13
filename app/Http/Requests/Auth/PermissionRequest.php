<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseFormRequest;
use App\Models\Auth\Permission;

class PermissionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $method = $this->route()->getActionMethod();

        return match ($method) {
            'store' => [
                'title' => 'required|string|max:50',
                'name' => 'required|string|max:100|unique:auth_permission,name',
                'type' => 'required|in:menu,api,button',
                'route' => 'nullable|string|max:200',
                'component' => 'nullable|string|max:200',
                'parent_id' => ['nullable', 'integer', 'min:0', function ($attribute, $value, $fail) {
                    if (!empty($value) && $value != 0 && !Permission::find($value)) {
                        $fail('父级权限不存在');
                    }
                }],
                'sort' => 'nullable|integer|min:0',
                'status' => 'nullable|integer|in:0,1',
                'meta' => 'nullable|array',
            ],
            'update' => [
                'title' => 'nullable|string|max:50',
                'name' => 'nullable|string|max:100|unique:auth_permission,name,' . $this->route('id'),
                'type' => 'nullable|in:menu,api,button',
                'route' => 'nullable|string|max:200',
                'component' => 'nullable|string|max:200',
                'parent_id' => ['nullable', 'integer', 'min:0', function ($attribute, $value, $fail) {
                    if (!empty($value) && $value != 0 && !Permission::find($value)) {
                        $fail('父级权限不存在');
                    }
                }],
                'sort' => 'nullable|integer|min:0',
                'status' => 'nullable|integer|in:0,1',
                'meta' => 'nullable|array',
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
            'title.required' => '请输入权限标题',
            'name.required' => '请输入权限编码',
            'name.unique' => '权限编码已存在',
            'type.required' => '请选择权限类型',
            'type.in' => '权限类型不正确',
            'ids.required' => '请选择数据',
            'status.required' => '请选择状态',
            'status.in' => '状态值不正确',
            'file.required' => '请上传文件',
            'file.mimes' => '文件格式不正确，仅支持 xlsx/xls',
        ];
    }
}
