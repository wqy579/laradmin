<?php

namespace Modules\Auth\Http\Requests;

use App\Http\Requests\BaseFormRequest;

class UserRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $method = $this->route()->getActionMethod();

        return match ($method) {
            'store' => [
                'username' => 'required|string|max:50|unique:auth_user,username',
                'password' => 'required|string|min:6',
                'real_name' => 'required|string|max:50',
                'email' => 'nullable|email|unique:auth_user,email',
                'phone' => 'nullable|string|max:20',
                'department_id' => 'nullable|integer|exists:auth_departments,id',
                'role_ids' => 'nullable|array',
                'role_ids.*' => 'integer|exists:auth_role,id',
                'status' => 'nullable|integer|in:0,1',
            ],
            'update' => [
                'username' => 'nullable|string|max:50|unique:auth_user,username,'.$this->route('id'),
                'password' => 'nullable|string|min:6',
                'real_name' => 'nullable|string|max:50',
                'email' => 'nullable|email|unique:auth_user,email,'.$this->route('id'),
                'phone' => 'nullable|string|max:20',
                'avatar' => 'nullable|string|max:500',
                'department_id' => 'nullable|integer|exists:auth_departments,id',
                'role_ids' => 'nullable|array',
                'role_ids.*' => 'integer|exists:auth_role,id',
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
            'batchAssignDepartment' => [
                'ids' => 'required|array',
                'ids.*' => 'integer',
                'department_id' => 'nullable|integer|exists:auth_departments,id',
            ],
            'batchAssignRoles' => [
                'ids' => 'required|array',
                'ids.*' => 'integer',
                'role_ids' => 'nullable|array',
                'role_ids.*' => 'integer|exists:auth_role,id',
            ],
            'export' => [
                'ids' => 'nullable|array',
                'ids.*' => 'integer',
                'fields' => 'nullable|array',
                'fields.*' => 'string|in:username,real_name,email,phone,department,roles,status,last_login_at,created_at',
                'filters' => 'nullable|array',
                'filters.username' => 'nullable|string',
                'filters.phone' => 'nullable|string',
                'filters.status' => 'nullable|integer|in:0,1',
                'filters.department_id' => 'nullable|integer',
            ],
            'import' => [
                'file' => 'required|file|mimes:xlsx,xls',
            ],
            'setUserOffline' => [
                'token' => 'nullable|string',
            ],
            default => [],
        };
    }

    public function messages(): array
    {
        return [
            'username.required' => '请输入用户名',
            'username.unique' => '用户名已存在',
            'password.required' => '请输入密码',
            'password.min' => '密码至少6个字符',
            'real_name.required' => '请输入真实姓名',
            'email.email' => '邮箱格式不正确',
            'email.unique' => '邮箱已存在',
            'ids.required' => '请选择数据',
            'ids.array' => '数据格式不正确',
            'status.required' => '请选择状态',
            'status.in' => '状态值不正确',
            'department_id.exists' => '部门不存在',
            'role_ids.*.exists' => '角色不存在',
            'file.required' => '请上传文件',
            'file.mimes' => '文件格式不正确，仅支持 xlsx/xls',
        ];
    }
}
