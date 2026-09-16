<?php

namespace Modules\Auth\Imports;

use Modules\Auth\Models\User;
use Modules\Auth\Models\Department;
use Modules\Auth\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class UserImport implements ToCollection, WithHeadingRow, WithValidation
{
    protected $successCount = 0;
    protected $errorCount = 0;
    protected $errors = [];

    /**
     * 处理导入数据
     */
    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            try {
                // 跳过空行
                if (empty($row['用户名'])) {
                    continue;
                }

                // 检查用户名是否已存在
                $exists = User::where('username', $row['用户名'])->exists();
                if ($exists) {
                    $this->addError($index + 2, '用户名已存在');
                    continue;
                }

                // 查找部门
                $departmentId = null;
                if (!empty($row['部门名称'])) {
                    $department = Department::where('name', $row['部门名称'])->first();
                    if (!$department) {
                        $this->addError($index + 2, '部门不存在: ' . $row['部门名称']);
                        continue;
                    }
                    $departmentId = $department->id;
                }

                // 查找角色
                $roleIds = [];
                if (!empty($row['角色名称（多个用逗号分隔）'])) {
                    $roleNames = array_map('trim', explode(',', $row['角色名称（多个用逗号分隔）']));
                    $roles = Role::whereIn('name', $roleNames)->get();

                    if ($roles->count() != count($roleNames)) {
                        $existingNames = $roles->pluck('name')->toArray();
                        $notFound = array_diff($roleNames, $existingNames);
                        $this->addError($index + 2, '角色不存在: ' . implode(', ', $notFound));
                        continue;
                    }
                    $roleIds = $roles->pluck('id')->toArray();
                }

                // 创建用户
                $user = User::create([
                    'username' => $row['用户名'],
                    'password' => Hash::make($row['密码']),
                    'real_name' => $row['真实姓名'],
                    'email' => $row['邮箱'] ?? null,
                    'phone' => $row['手机号'] ?? null,
                    'department_id' => $departmentId,
                    'status' => 1,
                ]);

                // 分配角色
                if (!empty($roleIds)) {
                    $user->roles()->attach($roleIds);
                }

                $this->successCount++;
            } catch (\Exception $e) {
                $this->addError($index + 2, $e->getMessage());
            }
        }
    }

    /**
     * 验证规则
     */
    public function rules(): array
    {
        return [
            '用户名' => 'required|string|max:50',
            '密码' => 'required|string|min:6',
            '真实姓名' => 'required|string|max:50',
            '邮箱' => 'nullable|email',
            '手机号' => 'nullable|string|max:20',
        ];
    }

    /**
     * 自定义验证消息
     */
    public function customValidationMessages(): array
    {
        return [
            '用户名.required' => '用户名不能为空',
            '密码.required' => '密码不能为空',
            '真实姓名.required' => '真实姓名不能为空',
            '密码.min' => '密码至少6位',
        ];
    }

    /**
     * 添加错误
     */
    protected function addError(int $row, string $message): void
    {
        $this->errorCount++;
        $this->errors[] = "第 {$row} 行: {$message}";
    }

    /**
     * 获取成功数量
     */
    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    /**
     * 获取错误数量
     */
    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    /**
     * 获取错误信息
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
