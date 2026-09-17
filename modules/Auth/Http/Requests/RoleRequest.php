<?php

namespace Modules\Auth\Http\Requests;

use App\Http\Requests\BaseFormRequest;

class RoleRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $method = $this->route()->getActionMethod();

        return match ($method) {
            'store' => [
                'name' => 'required|string|max:50',
                'code' => 'required|string|max:50|unique:auth_role,code',
                'description' => 'nullable|string|max:200',
                'sort' => 'nullable|integer|min:0',
                'status' => 'nullable|integer|in:0,1',
                'permission_ids' => 'nullable|array',
                'permission_ids.*' => 'integer|exists:auth_permission,id',
            ],
            'update' => [
                'name' => 'nullable|string|max:50',
                'code' => 'nullable|string|max:50|unique:auth_role,code,'.$this->route('id'),
                'description' => 'nullable|string|max:200',
                'sort' => 'nullable|integer|min:0',
                'status' => 'nullable|integer|in:0,1',
                'permission_ids' => 'nullable|array',
                'permission_ids.*' => 'integer|exists:auth_permission,id',
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
            'assignPermissions' => [
                'permission_ids' => 'required|array',
                'permission_ids.*' => 'integer|exists:auth_permission,id',
            ],
            'copy' => [
                'name' => 'required|string|max:50',
                'code' => 'required|string|max:50|unique:auth_role,code',
                'description' => 'nullable|string|max:200',
                'sort' => 'nullable|integer|min:0',
                'status' => 'nullable|integer|in:0,1',
            ],
            'batchCopy' => [
                'ids' => 'required|array',
                'ids.*' => 'integer',
                'name' => 'nullable|string|max:50',
                'code' => 'nullable|string|max:50',
                'description' => 'nullable|string|max:200',
                'sort' => 'nullable|integer|min:0',
                'status' => 'nullable|integer|in:0,1',
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
            'name.required' => '请输入角色名称',
            'code.required' => '请输入角色编码',
            'code.unique' => '角色编码已存在',
            'permission_ids.required' => '请选择权限',
            'permission_ids.*.exists' => '权限不存在',
            'ids.required' => '请选择数据',
            'status.required' => '请选择状态',
            'status.in' => '状态值不正确',
            'file.required' => '请上传文件',
            'file.mimes' => '文件格式不正确，仅支持 xlsx/xls',
        ];
    }
}
