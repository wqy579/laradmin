<?php

namespace Modules\Auth\Exports;

use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Modules\Auth\Models\Department;

class DepartmentExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    protected $departmentIds;

    public function __construct(array $departmentIds = [])
    {
        $this->departmentIds = $departmentIds;
    }

    /**
     * 获取数据集合
     */
    public function collection(): Enumerable
    {
        $query = Department::query();

        if (! empty($this->departmentIds)) {
            $query->whereIn('id', $this->departmentIds);
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
            '部门名称',
            '上级部门',
            '负责人',
            '联系电话',
            '排序',
            '状态',
            '创建时间',
        ];
    }

    /**
     * 映射数据
     */
    public function map($department): array
    {
        $parentName = '';
        if ($department->parent_id) {
            $parent = Department::find($department->parent_id);
            $parentName = $parent ? $parent->name : '';
        }

        return [
            $department->id,
            $department->name,
            $parentName,
            $department->leader,
            $department->phone,
            (int) $department->sort,
            $department->status == 1 ? '启用' : '禁用',
            $department->created_at ? (string) $department->created_at : '',
        ];
    }
}
