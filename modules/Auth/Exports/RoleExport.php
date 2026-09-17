<?php

namespace Modules\Auth\Exports;

use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Modules\Auth\Models\Role;

class RoleExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    protected $roleIds;

    public function __construct(array $roleIds = [])
    {
        $this->roleIds = $roleIds;
    }

    /**
     * 获取数据集合
     */
    public function collection(): Enumerable
    {
        $query = Role::with(['permissions']);

        if (! empty($this->roleIds)) {
            $query->whereIn('id', $this->roleIds);
        }

        return $query->get();
    }

    /**
     * 设置表头
     */
    public function headings(): array
    {
        return [
            'ID',
            '角色名称',
            '角色编码',
            '描述',
            '权限',
            '排序',
            '状态',
            '创建时间',
        ];
    }

    /**
     * 映射数据
     */
    public function map($role): array
    {
        return [
            $role->id,
            $role->name,
            $role->code,
            $role->description,
            $role->permissions->pluck('title')->implode(','),
            (int) $role->sort,
            $role->status == 1 ? '启用' : '禁用',
            $role->created_at ? (string) $role->created_at : '',
        ];
    }
}
