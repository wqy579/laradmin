<?php

namespace App\Imports;

use App\Models\Auth\Permission;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class PermissionImport implements ToCollection, WithHeadingRow, WithValidation
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
                if (empty($row['权限标题'])) {
                    continue;
                }

                // 检查权限编码是否已存在
                $exists = Permission::where('name', $row['权限编码'])->exists();
                if ($exists) {
                    $this->addError($index + 2, '权限编码已存在');
                    continue;
                }

                // 查找父级权限
                $parentId = null;
                if (!empty($row['父级ID']) && $row['父级ID'] != 0) {
                    $parent = Permission::find($row['父级ID']);
                    if (!$parent) {
                        $this->addError($index + 2, '父级权限不存在');
                        continue;
                    }
                    $parentId = $parent->id;
                }

                // 解析类型
                $type = $this->parseType($row['权限类型']);

                // 解析元数据
                $meta = null;
                if ($type === 'menu' && !empty($row['元数据'])) {
                    // 元数据格式: icon:Setting,hidden:false,keepAlive:false
                    $meta = $this->parseMeta($row['元数据']);
                }

                // 创建权限
                Permission::create([
                    'title' => $row['权限标题'],
                    'name' => $row['权限编码'],
                    'type' => $type,
                    'parent_id' => $parentId,
                    'path' => $row['路由路径'] ?? null,
                    'component' => $row['前端组件'] ?? null,
                    'meta' => $meta,
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
            '权限标题' => 'required|string|max:50',
            '权限编码' => 'required|string|max:100',
            '权限类型' => 'required|in:菜单,API接口,按钮,链接,menu,api,button,url',
            '父级ID' => 'nullable|integer|min:0',
            '路由路径' => 'nullable|string|max:200',
            '前端组件' => 'nullable|string|max:200',
            '排序' => 'nullable|integer|min:0',
        ];
    }

    /**
     * 自定义验证消息
     */
    public function customValidationMessages(): array
    {
        return [
            '权限标题.required' => '权限标题不能为空',
            '权限编码.required' => '权限编码不能为空',
            '权限类型.required' => '权限类型不能为空',
        ];
    }

    /**
     * 解析类型
     */
    protected function parseType($type): string
    {
        $typeMap = [
            '菜单' => 'menu',
            'API接口' => 'api',
            '接口' => 'api',
            '按钮' => 'button',
            '链接' => 'url',
        ];

        return $typeMap[$type] ?? $type;
    }

    /**
     * 解析元数据
     */
    protected function parseMeta($metaString): ?string
    {
        if (empty($metaString)) {
            return null;
        }

        // 简单解析，实际项目中可能需要更复杂的解析逻辑
        return $metaString;
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
