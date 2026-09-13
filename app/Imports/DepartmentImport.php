<?php

namespace App\Imports;

use App\Models\Auth\Department;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class DepartmentImport implements ToCollection, WithHeadingRow, WithValidation
{
    protected $successCount = 0;
    protected $errorCount = 0;
    protected $errors = [];

    /**
     * 处理导入数据
     */
    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            try {
                // 跳过空行
                if (empty($row['部门名称'])) {
                    continue;
                }

                // 检查部门名称是否已存在
                $exists = Department::where('name', $row['部门名称'])->exists();
                if ($exists) {
                    $this->addError($index + 2, '部门名称已存在: ' . $row['部门名称']);
                    continue;
                }

                // 查找父级部门
                $parentId = null;
                if (!empty($row['上级部门名称'])) {
                    $parent = Department::where('name', $row['上级部门名称'])->first();
                    if (!$parent) {
                        $this->addError($index + 2, '上级部门不存在: ' . $row['上级部门名称']);
                        continue;
                    }
                    $parentId = $parent->id;
                }

                // 创建部门
                Department::create([
                    'name' => $row['部门名称'],
                    'parent_id' => $parentId,
                    'leader' => $row['负责人'] ?? null,
                    'phone' => $row['联系电话'] ?? null,
                    'sort' => $row['排序'] ?? 0,
                    'status' => 1,
                ]);

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
            '部门名称' => 'required|string|max:50',
            '上级部门名称' => 'nullable|string|max:50',
            '负责人' => 'nullable|string|max:50',
            '联系电话' => 'nullable|string|max:20',
            '排序' => 'nullable|integer|min:0',
        ];
    }

    /**
     * 自定义验证消息
     */
    public function customValidationMessages(): array
    {
        return [
            '部门名称.required' => '部门名称不能为空',
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
