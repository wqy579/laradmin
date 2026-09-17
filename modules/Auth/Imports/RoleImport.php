<?php

namespace Modules\Auth\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;

class RoleImport implements ToCollection, WithHeadingRow, WithValidation
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
                if (empty($row['角色名称'])) {
                    continue;
                }

                // 检查角色编码是否已存在
                $exists = Role::where('code', $row['角色编码'])->exists();
                if ($exists) {
                    $this->addError($index + 2, '角色编码已存在');

                    continue;
                }

                // 查找权限
                $permissionIds = [];
                if (! empty($row['权限（多个用逗号分隔）'])) {
                    $permissionNames = array_map('trim', explode(',', $row['权限（多个用逗号分隔）']));
                    $permissions = Permission::whereIn('title', $permissionNames)->get();

                    if ($permissions->count() != count($permissionNames)) {
                        $existingNames = $permissions->pluck('title')->toArray();
                        $notFound = array_diff($permissionNames, $existingNames);
                        $this->addError($index + 2, '权限不存在: '.implode(', ', $notFound));

                        continue;
                    }
                    $permissionIds = $permissions->pluck('id')->toArray();
                }

                // 创建角色
                $role = Role::create([
                    'name' => $row['角色名称'],
                    'code' => $row['角色编码'],
                    'description' => $row['描述'] ?? null,
                    'sort' => $row['排序'] ?? 0,
                    'status' => 1,
                ]);

                // 分配权限
                if (! empty($permissionIds)) {
                    $role->permissions()->attach($permissionIds);
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
            '角色名称' => 'required|string|max:50',
            '角色编码' => 'required|string|max:50',
            '描述' => 'nullable|string|max:200',
            '排序' => 'nullable|integer|min:0',
        ];
    }

    /**
     * 自定义验证消息
     */
    public function customValidationMessages(): array
    {
        return [
            '角色名称.required' => '角色名称不能为空',
            '角色编码.required' => '角色编码不能为空',
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
