<?php

namespace App\Services\Auth;

use App\Models\Auth\User;
use App\Models\Auth\Department;
use App\Models\Auth\Role;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\UserExport;
use App\Exports\DepartmentExport;
use App\Exports\RoleExport;
use App\Exports\PermissionExport;
use App\Imports\UserImport;
use App\Imports\DepartmentImport;
use App\Imports\RoleImport;
use App\Imports\PermissionImport;

class ImportExportService
{
    /**
     * 下载用户导入模板
     */
    public function downloadUserTemplate(): string
    {
        $filename = 'user_import_template_' . date('YmdHis') . '.xlsx';
        $path = storage_path('app/exports/' . $filename);

        // 确保目录存在
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        // 使用模板数据创建Excel
        $templateData = [
            [
                '用户名*',
                '密码*',
                '真实姓名*',
                '邮箱',
                '手机号',
                '部门名称',
                '角色名称（多个用逗号分隔）',
                '备注',
            ],
            [
                'test001',
                '123456',
                '测试用户001',
                'test001@example.com',
                '13800138001',
                '技术部',
                '管理员',
                '示例数据',
            ],
        ];

        Excel::store(new \App\Exports\GenericExport($templateData), 'exports/' . $filename, 'public');

        return $filename;
    }

    /**
     * 下载部门导入模板
     */
    public function downloadDepartmentTemplate(): string
    {
        $filename = 'department_import_template_' . date('YmdHis') . '.xlsx';

        // 确保目录存在
        if (!is_dir(storage_path('app/exports'))) {
            mkdir(storage_path('app/exports'), 0755, true);
        }

        $templateData = [
            [
                '部门名称*',
                '上级部门名称',
                '负责人',
                '联系电话',
                '排序',
                '备注',
            ],
            [
                '前端开发组',
                '技术部',
                '张三',
                '13800138001',
                '1',
                '示例数据',
            ],
        ];

        Excel::store(new \App\Exports\GenericExport($templateData), 'exports/' . $filename, 'public');

        return $filename;
    }

    /**
     * 导出用户数据
     */
    public function exportUsers(array $userIds = []): string
    {
        $filename = 'users_export_' . date('YmdHis') . '.xlsx';

        if (!is_dir(storage_path('app/exports'))) {
            mkdir(storage_path('app/exports'), 0755, true);
        }

        Excel::store(new UserExport($userIds), 'exports/' . $filename, 'public');

        return $filename;
    }

    /**
     * 导出用户数据（支持字段选择和筛选）
     */
    public function exportUsersWithFields(array $fields = [], array $filters = []): string
    {
        $filename = 'users_export_' . date('YmdHis') . '.xlsx';

        if (!is_dir(storage_path('app/exports'))) {
            mkdir(storage_path('app/exports'), 0755, true);
        }

        Excel::store(new UserExport([], $fields, $filters), 'exports/' . $filename, 'public');

        return $filename;
    }

    /**
     * 导出部门数据
     */
    public function exportDepartments(array $departmentIds = []): string
    {
        $filename = 'departments_export_' . date('YmdHis') . '.xlsx';

        // 确保目录存在
        if (!is_dir(storage_path('app/exports'))) {
            mkdir(storage_path('app/exports'), 0755, true);
        }

        Excel::store(new DepartmentExport($departmentIds), 'exports/' . $filename, 'public');

        return $filename;
    }

    /**
     * 导入用户数据
     */
    public function importUsers(string $filePath, string $realPath): array
    {
        if (!file_exists($realPath)) {
            throw ValidationException::withMessages([
                'file' => ['文件不存在'],
            ]);
        }

        $import = new UserImport();
        Excel::import($import, $realPath);

        // 删除临时文件
        if (file_exists($realPath)) {
            unlink($realPath);
        }

        return [
            'success_count' => $import->getSuccessCount(),
            'error_count' => $import->getErrorCount(),
            'errors' => $import->getErrors(),
        ];
    }

    /**
     * 导入部门数据
     */
    public function importDepartments(string $filePath, string $realPath): array
    {
        if (!file_exists($realPath)) {
            throw ValidationException::withMessages([
                'file' => ['文件不存在'],
            ]);
        }

        $import = new DepartmentImport();
        Excel::import($import, $realPath);

        // 删除临时文件
        if (file_exists($realPath)) {
            unlink($realPath);
        }

        return [
            'success_count' => $import->getSuccessCount(),
            'error_count' => $import->getErrorCount(),
            'errors' => $import->getErrors(),
        ];
    }

    /**
     * 获取导出文件路径
     */
    public function getExportFilePath(string $filename): string
    {
        return \Illuminate\Support\Facades\Storage::disk('public')->path('exports/' . $filename);
    }

    /**
     * 下载角色导入模板
     */
    public function downloadRoleTemplate(): string
    {
        $filename = 'role_import_template_' . date('YmdHis') . '.xlsx';

        // 确保目录存在
        if (!is_dir(storage_path('app/exports'))) {
            mkdir(storage_path('app/exports'), 0755, true);
        }

        $templateData = [
            [
                '角色名称*',
                '角色编码*',
                '描述',
                '权限（多个用逗号分隔）',
                '排序',
            ],
            [
                '测试角色',
                'test_role',
                '这是一个测试角色',
                '查看用户,编辑用户',
                '1',
            ],
        ];

        Excel::store(new \App\Exports\GenericExport($templateData), 'exports/' . $filename, 'public');

        return $filename;
    }

    /**
     * 导出角色数据
     */
    public function exportRoles(array $roleIds = []): string
    {
        $filename = 'roles_export_' . date('YmdHis') . '.xlsx';

        // 确保目录存在
        if (!is_dir(storage_path('app/exports'))) {
            mkdir(storage_path('app/exports'), 0755, true);
        }

        Excel::store(new RoleExport($roleIds), 'exports/' . $filename, 'public');

        return $filename;
    }

    /**
     * 导入角色数据
     */
    public function importRoles(string $filePath, string $realPath): array
    {
        if (!file_exists($realPath)) {
            throw ValidationException::withMessages([
                'file' => ['文件不存在'],
            ]);
        }

        $import = new RoleImport();
        Excel::import($import, $realPath);

        // 删除临时文件
        if (file_exists($realPath)) {
            unlink($realPath);
        }

        return [
            'success_count' => $import->getSuccessCount(),
            'error_count' => $import->getErrorCount(),
            'errors' => $import->getErrors(),
        ];
    }

    /**
     * 下载权限导入模板
     */
    public function downloadPermissionTemplate(): string
    {
        $filename = 'permission_import_template_' . date('YmdHis') . '.xlsx';

        // 确保目录存在
        if (!is_dir(storage_path('app/exports'))) {
            mkdir(storage_path('app/exports'), 0755, true);
        }

        $templateData = [
            [
                '权限标题*',
                '权限编码*',
                '权限类型*',
                '父级ID',
                '路由路径',
                '前端组件',
                '元数据',
                '排序',
            ],
            [
                '系统管理',
                'system',
                '菜单',
                '0',
                '/system',
                null,
                'icon:Setting',
                '1',
            ],
        ];

        Excel::store(new \App\Exports\GenericExport($templateData), 'exports/' . $filename, 'public');

        return $filename;
    }

    /**
     * 导出权限数据
     */
    public function exportPermissions(array $permissionIds = []): string
    {
        $filename = 'permissions_export_' . date('YmdHis') . '.xlsx';

        // 确保目录存在
        if (!is_dir(storage_path('app/exports'))) {
            mkdir(storage_path('app/exports'), 0755, true);
        }

        Excel::store(new PermissionExport($permissionIds), 'exports/' . $filename, 'public');

        return $filename;
    }

    /**
     * 导入权限数据
     */
    public function importPermissions(string $filePath, string $realPath): array
    {
        if (!file_exists($realPath)) {
            throw ValidationException::withMessages([
                'file' => ['文件不存在'],
            ]);
        }

        $import = new PermissionImport();
        Excel::import($import, $realPath);

        // 删除临时文件
        if (file_exists($realPath)) {
            unlink($realPath);
        }

        return [
            'success_count' => $import->getSuccessCount(),
            'error_count' => $import->getErrorCount(),
            'errors' => $import->getErrors(),
        ];
    }

    /**
     * 删除导出文件
     */
    public function deleteExportFile(string $filename): bool
    {
        $path = $this->getExportFilePath($filename);
        if (file_exists($path)) {
            return unlink($path);
        }
        return true;
    }
}
