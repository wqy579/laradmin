<?php

namespace Modules\Auth\Exports;

use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Modules\Auth\Models\Permission;

class PermissionExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    protected $permissionIds;

    public function __construct(array $permissionIds = [])
    {
        $this->permissionIds = $permissionIds;
    }

    /**
     * 获取数据集合
     */
    public function collection(): Enumerable
    {
        $query = Permission::query();

        if (! empty($this->permissionIds)) {
            $query->whereIn('id', $this->permissionIds);
        }

        return $query->orderBy('sort')->get();
    }

    /**
     * 设置表头
     */
    public function headings(): array
    {
        return [
            'ID',
            '权限标题',
            '权限编码',
            '权限类型',
            '父级ID',
            '路由路径',
            '前端组件',
            '排序',
            '状态',
            '创建时间',
        ];
    }

    /**
     * 映射数据
     */
    public function map($permission): array
    {
        return [
            $permission->id,
            $permission->title,
            $permission->name,
            $this->getTypeName($permission->type),
            $permission->parent_id ?: 0,
            $permission->path,
            $permission->component,
            (int) $permission->sort,
            $permission->status == 1 ? '启用' : '禁用',
            $permission->created_at ? (string) $permission->created_at : '',
        ];
    }

    /**
     * 获取类型名称
     */
    protected function getTypeName($type): string
    {
        $types = [
            'menu' => '菜单',
            'api' => 'API接口',
            'button' => '按钮',
            'url' => '链接',
        ];

        return $types[$type] ?? $type;
    }
}
