<?php

namespace Modules\Auth\Exports;

use Modules\Auth\Models\User;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class UserExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    protected $userIds;
    protected $fields;
    protected $filters;

    protected $fieldMap = [
        'id' => ['title' => 'ID', 'key' => 'id'],
        'username' => ['title' => '用户名', 'key' => 'username'],
        'real_name' => ['title' => '姓名', 'key' => 'real_name'],
        'email' => ['title' => '邮箱', 'key' => 'email'],
        'phone' => ['title' => '手机号', 'key' => 'phone'],
        'department' => ['title' => '部门', 'key' => 'department'],
        'roles' => ['title' => '角色', 'key' => 'roles'],
        'status' => ['title' => '状态', 'key' => 'status'],
        'last_login_at' => ['title' => '最后登录时间', 'key' => 'last_login_at'],
        'created_at' => ['title' => '创建时间', 'key' => 'created_at'],
    ];

    public function __construct(array $userIds = [], array $fields = [], array $filters = [])
    {
        $this->userIds = $userIds;
        $this->fields = empty($fields) ? array_keys($this->fieldMap) : $fields;
        $this->filters = $filters;
    }

    public function collection()
    {
        $query = User::with(['department', 'roles']);

        if (!empty($this->userIds)) {
            $query->whereIn('id', $this->userIds);
        }

        if (!empty($this->filters)) {
            if (!empty($this->filters['username'])) {
                $query->where('username', 'like', '%' . $this->filters['username'] . '%');
            }
            if (!empty($this->filters['phone'])) {
                $query->where('phone', 'like', '%' . $this->filters['phone'] . '%');
            }
            if (isset($this->filters['status']) && $this->filters['status'] !== '') {
                $query->where('status', $this->filters['status']);
            }
            if (!empty($this->filters['department_id'])) {
                $query->where('department_id', $this->filters['department_id']);
            }
        }

        return $query->get();
    }

    public function headings(): array
    {
        $headings = [];
        foreach ($this->fields as $field) {
            if (isset($this->fieldMap[$field])) {
                $headings[] = $this->fieldMap[$field]['title'];
            }
        }
        return $headings;
    }

    public function map($user): array
    {
        $row = [];
        foreach ($this->fields as $field) {
            $value = match ($field) {
                'id' => $user->id,
                'username' => $user->username,
                'real_name' => $user->real_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'department' => $user->department ? $user->department->name : '',
                'roles' => $user->roles->pluck('name')->implode(','),
                'status' => $user->status == 1 ? '正常' : '禁用',
                'last_login_at' => $user->last_login_at ? (string)$user->last_login_at : '',
                'created_at' => $user->created_at ? (string)$user->created_at : '',
                default => '',
            };
            $row[] = $value;
        }
        return $row;
    }
}
