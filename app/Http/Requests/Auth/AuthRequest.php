<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseFormRequest;

class AuthRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $method = $this->route()->getActionMethod();

        return match ($method) {
            'login' => [
                'username' => 'required|string',
                'password' => 'required|string',
            ],
            'updateMe' => [
                'username' => 'sometimes|string|max:50',
                'real_name' => 'sometimes|string|max:50',
                'phone' => 'sometimes|string|max:20',
                'email' => 'sometimes|email|max:100',
            ],
            'resetPassword' => [
                'username' => 'required|string',
                'password' => 'required|string|min:6|confirmed',
            ],
            'changePassword' => [
                'old_password' => 'required|string',
                'password' => 'required|string|min:6|confirmed',
            ],
            default => [],
        };
    }

    public function messages(): array
    {
        return [
            'username.required' => '请输入用户名',
            'password.required' => '请输入密码',
            'password.min' => '密码至少6个字符',
            'password.confirmed' => '两次密码输入不一致',
            'old_password.required' => '请输入原密码',
            'email.email' => '邮箱格式不正确',
        ];
    }
}
