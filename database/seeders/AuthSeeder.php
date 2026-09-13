<?php

namespace Database\Seeders;

use Modules\Auth\Models\User;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Department;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AuthSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // SQLite 不支持 SET FOREIGN_KEY_CHECKS，使用 PRAGMA
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF;');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        }

        // 清空表数据
        DB::table('auth_role_permission')->truncate();
        DB::table('auth_user_role')->truncate();
        Permission::truncate();
        Role::truncate();
        User::truncate();
        Department::truncate();

        $this->createPermissions();
        $this->createRoles();
        $this->createDepartments();
        $this->createUsers();
        $this->assignPermissionsToRole();
        $this->assignRoleToUser();

        $this->command->info('Auth module data seeded successfully!');

        // 恢复外键约束
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }

    /**
     * 创建权限节点
     */
    private function createPermissions(): void
    {
        $permissions = [
            // 首页顶级菜单
            [
                'title' => '首页',
                'name' => 'home',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/home',
                'component' => null,
                'meta' => [
                    'icon' => 'ElIconHomeFilled',
                    'hidden' => false,
                    'hiddenBreadcrumb' => false,
                    'affix' => true,
                ],
                'sort' => 1,
                'status' => 1,
            ],
            // 仪表盘
            [
                'title' => '仪表盘',
                'name' => 'home.dashboard',
                'type' => 'menu',
                'parent_id' => 0, // 稍后更新为首页菜单的ID
                'path' => '/dashboard',
                'component' => 'home/index',
                'meta' => [
                    'icon' => 'ElIconDataLine',
                    'hidden' => false,
                    'hiddenBreadcrumb' => false,
                    'affix' => true,
                ],
                'sort' => 1,
                'status' => 1,
            ],
            // 个人中心
            [
                'title' => '个人中心',
                'name' => 'home.profile',
                'type' => 'menu',
                'parent_id' => 0, // 稍后更新为首页菜单的ID
                'path' => '/ucenter',
                'component' => 'ucenter/index',
                'meta' => [
                    'icon' => 'ElIconUser',
                    'hidden' => false,
                    'hiddenBreadcrumb' => false,
                ],
                'sort' => 2,
                'status' => 1,
            ],
            [
                'title' => '查看个人信息',
                'name' => 'home.profile.view',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为个人中心菜单的ID
                'path' => null,
                'component' => null,
                'meta' => null,
                'sort' => 1,
                'status' => 1,
            ],
            [
                'title' => '编辑个人信息',
                'name' => 'home.profile.update',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为个人中心菜单的ID
                'path' => null,
                'component' => null,
                'meta' => null,
                'sort' => 2,
                'status' => 1,
            ],
            [
                'title' => '修改密码',
                'name' => 'home.profile.change-password',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为个人中心菜单的ID
                'path' => null,
                'component' => null,
                'meta' => null,
                'sort' => 3,
                'status' => 1,
            ],

            // 权限顶级菜单
            [
                'title' => '权限',
                'name' => 'auth',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/auth',
                'component' => null,
                'meta' => [
                    'icon' => 'ElIconUser',
                    'hidden' => false,
                    'hiddenBreadcrumb' => false,
                ],
                'sort' => 2,
                'status' => 1,
            ],
            // 用户管理
            [
                'title' => '用户管理',
                'name' => 'auth.users',
                'type' => 'menu',
                'parent_id' => 0, // 稍后更新为权限菜单的ID
                'path' => '/auth/user',
                'component' => 'auth/user/index',
                'meta' => [
                    'icon' => 'AIconUserOutlined',
                    'hidden' => false,
                    'hiddenBreadcrumb' => false,
                ],
                'sort' => 1,
                'status' => 1,
            ],
            [
                'title' => '查看用户',
                'name' => 'auth.users.view',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为用户管理菜单的ID
                'path' => 'admin.user.index',
                'component' => null,
                'meta' => null,
                'sort' => 1,
                'status' => 1,
            ],
            [
                'title' => '创建用户',
                'name' => 'auth.users.create',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为用户管理菜单的ID
                'path' => 'admin.user.store',
                'component' => null,
                'meta' => null,
                'sort' => 2,
                'status' => 1,
            ],
            [
                'title' => '编辑用户',
                'name' => 'auth.users.update',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为用户管理菜单的ID
                'path' => 'admin.user.update',
                'component' => null,
                'meta' => null,
                'sort' => 3,
                'status' => 1,
            ],
            [
                'title' => '删除用户',
                'name' => 'auth.users.delete',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为用户管理菜单的ID
                'path' => 'admin.user.destroy',
                'component' => null,
                'meta' => null,
                'sort' => 4,
                'status' => 1,
            ],
            [
                'title' => '批量删除用户',
                'name' => 'auth.users.batch-delete',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为用户管理菜单的ID
                'path' => 'admin.user.batch-delete',
                'component' => null,
                'meta' => null,
                'sort' => 5,
                'status' => 1,
            ],
            [
                'title' => '导出用户',
                'name' => 'auth.users.export',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为用户管理菜单的ID
                'path' => 'admin.user.export',
                'component' => null,
                'meta' => null,
                'sort' => 6,
                'status' => 1,
            ],
            [
                'title' => '导入用户',
                'name' => 'auth.users.import',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为用户管理菜单的ID
                'path' => 'admin.user.import',
                'component' => null,
                'meta' => null,
                'sort' => 7,
                'status' => 1,
            ],
            // 角色管理
            [
                'title' => '角色管理',
                'name' => 'auth.roles',
                'type' => 'menu',
                'parent_id' => 0, // 稍后更新为权限菜单的ID
                'path' => '/auth/role',
                'component' => 'auth/role/index',
                'meta' => [
                    'icon' => 'ElIconUserFilled',
                    'hidden' => false,
                    'hiddenBreadcrumb' => false,
                ],
                'sort' => 2,
                'status' => 1,
            ],
            [
                'title' => '查看角色',
                'name' => 'auth.roles.view',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为角色管理菜单的ID
                'path' => 'admin.role.index',
                'component' => null,
                'meta' => null,
                'sort' => 1,
                'status' => 1,
            ],
            [
                'title' => '创建角色',
                'name' => 'auth.roles.create',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为角色管理菜单的ID
                'path' => 'admin.role.store',
                'component' => null,
                'meta' => null,
                'sort' => 2,
                'status' => 1,
            ],
            [
                'title' => '编辑角色',
                'name' => 'auth.roles.update',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为角色管理菜单的ID
                'path' => 'admin.role.update',
                'component' => null,
                'meta' => null,
                'sort' => 3,
                'status' => 1,
            ],
            [
                'title' => '删除角色',
                'name' => 'auth.roles.delete',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为角色管理菜单的ID
                'path' => 'admin.role.destroy',
                'component' => null,
                'meta' => null,
                'sort' => 4,
                'status' => 1,
            ],
            [
                'title' => '批量删除角色',
                'name' => 'auth.roles.batch-delete',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为角色管理菜单的ID
                'path' => 'admin.role.batch-delete',
                'component' => null,
                'meta' => null,
                'sort' => 5,
                'status' => 1,
            ],
            [
                'title' => '分配权限',
                'name' => 'auth.roles.assign-permissions',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为角色管理菜单的ID
                'path' => 'admin.role.assign-permissions',
                'component' => null,
                'meta' => null,
                'sort' => 6,
                'status' => 1,
            ],
            // 权限管理
            [
                'title' => '权限管理',
                'name' => 'auth.permissions',
                'type' => 'menu',
                'parent_id' => 0, // 稍后更新为权限菜单的ID
                'path' => '/auth/permission',
                'component' => 'auth/permission/index',
                'meta' => [
                    'icon' => 'ElIconLock',
                    'hidden' => false,
                    'hiddenBreadcrumb' => false,
                ],
                'sort' => 3,
                'status' => 1,
            ],
            [
                'title' => '查看权限',
                'name' => 'auth.permissions.view',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为权限管理菜单的ID
                'path' => 'admin.permission.index',
                'component' => null,
                'meta' => null,
                'sort' => 1,
                'status' => 1,
            ],
            [
                'title' => '创建权限',
                'name' => 'auth.permissions.create',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为权限管理菜单的ID
                'path' => 'admin.permission.store',
                'component' => null,
                'meta' => null,
                'sort' => 2,
                'status' => 1,
            ],
            [
                'title' => '编辑权限',
                'name' => 'auth.permissions.update',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为权限管理菜单的ID
                'path' => 'admin.permission.update',
                'component' => null,
                'meta' => null,
                'sort' => 3,
                'status' => 1,
            ],
            [
                'title' => '删除权限',
                'name' => 'auth.permissions.delete',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为权限管理菜单的ID
                'path' => 'admin.permission.destroy',
                'component' => null,
                'meta' => null,
                'sort' => 4,
                'status' => 1,
            ],
            [
                'title' => '批量删除权限',
                'name' => 'auth.permissions.batch-delete',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为权限管理菜单的ID
                'path' => 'admin.permission.batch-delete',
                'component' => null,
                'meta' => null,
                'sort' => 5,
                'status' => 1,
            ],
            // 部门管理
            [
                'title' => '部门管理',
                'name' => 'auth.departments',
                'type' => 'menu',
                'parent_id' => 0, // 稍后更新为权限菜单的ID
                'path' => '/auth/department',
                'component' => 'auth/department/index',
                'meta' => [
                    'icon' => 'ElIconOfficeBuilding',
                    'hidden' => false,
                    'hiddenBreadcrumb' => false,
                ],
                'sort' => 4,
                'status' => 1,
            ],
            [
                'title' => '查看部门',
                'name' => 'auth.departments.view',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为部门管理菜单的ID
                'path' => 'admin.department.index',
                'component' => null,
                'meta' => null,
                'sort' => 1,
                'status' => 1,
            ],
            [
                'title' => '创建部门',
                'name' => 'auth.departments.create',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为部门管理菜单的ID
                'path' => 'admin.department.store',
                'component' => null,
                'meta' => null,
                'sort' => 2,
                'status' => 1,
            ],
            [
                'title' => '编辑部门',
                'name' => 'auth.departments.update',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为部门管理菜单的ID
                'path' => 'admin.department.update',
                'component' => null,
                'meta' => null,
                'sort' => 3,
                'status' => 1,
            ],
            [
                'title' => '删除部门',
                'name' => 'auth.departments.delete',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为部门管理菜单的ID
                'path' => 'admin.department.destroy',
                'component' => null,
                'meta' => null,
                'sort' => 4,
                'status' => 1,
            ],
            [
                'title' => '批量删除部门',
                'name' => 'auth.departments.batch-delete',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为部门管理菜单的ID
                'path' => 'admin.department.batch-delete',
                'component' => null,
                'meta' => null,
                'sort' => 5,
                'status' => 1,
            ],
        ];

        foreach ($permissions as $permission) {
            Permission::create($permission);
        }

        // 更新parent_id
        $this->updateParentIds();
    }

    /**
     * 更新parent_id，建立层级关系
     */
    private function updateParentIds(): void
    {
        $permissions = Permission::all();

        // 获取顶级菜单ID
        $homeMenu = $permissions->where('name', 'home')->first();
        $authMenu = $permissions->where('name', 'auth')->first();

        // 获取首页子菜单ID
        $dashboardMenu = $permissions->where('name', 'home.dashboard')->first();
        $profileMenu = $permissions->where('name', 'home.profile')->first();

        // 获取权限子菜单ID
        $usersMenu = $permissions->where('name', 'auth.users')->first();
        $rolesMenu = $permissions->where('name', 'auth.roles')->first();
        $permissionsMenu = $permissions->where('name', 'auth.permissions')->first();
        $departmentsMenu = $permissions->where('name', 'auth.departments')->first();

        // 更新首页子菜单的parent_id
        if ($homeMenu) {
            if ($dashboardMenu) {
                $dashboardMenu->update(['parent_id' => $homeMenu->id]);
            }
            if ($profileMenu) {
                $profileMenu->update(['parent_id' => $homeMenu->id]);
            }
        }

        // 更新权限子菜单的parent_id
        if ($authMenu) {
            if ($usersMenu) {
                $usersMenu->update(['parent_id' => $authMenu->id]);
            }
            if ($rolesMenu) {
                $rolesMenu->update(['parent_id' => $authMenu->id]);
            }
            if ($permissionsMenu) {
                $permissionsMenu->update(['parent_id' => $authMenu->id]);
            }
            if ($departmentsMenu) {
                $departmentsMenu->update(['parent_id' => $authMenu->id]);
            }
        }

        // 更新按钮权限的parent_id - 首页部分
        $profileViewBtn = $permissions->where('name', 'home.profile.view')->first();
        $profileUpdateBtn = $permissions->where('name', 'home.profile.update')->first();
        $profilePasswordBtn = $permissions->where('name', 'home.profile.change-password')->first();
        if ($profileMenu) {
            if ($profileViewBtn) {
                $profileViewBtn->update(['parent_id' => $profileMenu->id]);
            }
            if ($profileUpdateBtn) {
                $profileUpdateBtn->update(['parent_id' => $profileMenu->id]);
            }
            if ($profilePasswordBtn) {
                $profilePasswordBtn->update(['parent_id' => $profileMenu->id]);
            }
        }

        // 更新按钮权限的parent_id - 用户管理
        $userViewBtn = $permissions->where('name', 'auth.users.view')->first();
        $userCreateBtn = $permissions->where('name', 'auth.users.create')->first();
        $userUpdateBtn = $permissions->where('name', 'auth.users.update')->first();
        $userDeleteBtn = $permissions->where('name', 'auth.users.delete')->first();
        $userBatchDeleteBtn = $permissions->where('name', 'auth.users.batch-delete')->first();
        $userExportBtn = $permissions->where('name', 'auth.users.export')->first();
        $userImportBtn = $permissions->where('name', 'auth.users.import')->first();
        if ($usersMenu) {
            if ($userViewBtn) {
                $userViewBtn->update(['parent_id' => $usersMenu->id]);
            }
            if ($userCreateBtn) {
                $userCreateBtn->update(['parent_id' => $usersMenu->id]);
            }
            if ($userUpdateBtn) {
                $userUpdateBtn->update(['parent_id' => $usersMenu->id]);
            }
            if ($userDeleteBtn) {
                $userDeleteBtn->update(['parent_id' => $usersMenu->id]);
            }
            if ($userBatchDeleteBtn) {
                $userBatchDeleteBtn->update(['parent_id' => $usersMenu->id]);
            }
            if ($userExportBtn) {
                $userExportBtn->update(['parent_id' => $usersMenu->id]);
            }
            if ($userImportBtn) {
                $userImportBtn->update(['parent_id' => $usersMenu->id]);
            }
        }

        // 更新按钮权限的parent_id - 角色管理
        $roleViewBtn = $permissions->where('name', 'auth.roles.view')->first();
        $roleCreateBtn = $permissions->where('name', 'auth.roles.create')->first();
        $roleUpdateBtn = $permissions->where('name', 'auth.roles.update')->first();
        $roleDeleteBtn = $permissions->where('name', 'auth.roles.delete')->first();
        $roleBatchDeleteBtn = $permissions->where('name', 'auth.roles.batch-delete')->first();
        $roleAssignBtn = $permissions->where('name', 'auth.roles.assign-permissions')->first();
        if ($rolesMenu) {
            if ($roleViewBtn) {
                $roleViewBtn->update(['parent_id' => $rolesMenu->id]);
            }
            if ($roleCreateBtn) {
                $roleCreateBtn->update(['parent_id' => $rolesMenu->id]);
            }
            if ($roleUpdateBtn) {
                $roleUpdateBtn->update(['parent_id' => $rolesMenu->id]);
            }
            if ($roleDeleteBtn) {
                $roleDeleteBtn->update(['parent_id' => $rolesMenu->id]);
            }
            if ($roleBatchDeleteBtn) {
                $roleBatchDeleteBtn->update(['parent_id' => $rolesMenu->id]);
            }
            if ($roleAssignBtn) {
                $roleAssignBtn->update(['parent_id' => $rolesMenu->id]);
            }
        }

        // 更新按钮权限的parent_id - 权限管理
        $permViewBtn = $permissions->where('name', 'auth.permissions.view')->first();
        $permCreateBtn = $permissions->where('name', 'auth.permissions.create')->first();
        $permUpdateBtn = $permissions->where('name', 'auth.permissions.update')->first();
        $permDeleteBtn = $permissions->where('name', 'auth.permissions.delete')->first();
        $permBatchDeleteBtn = $permissions->where('name', 'auth.permissions.batch-delete')->first();
        if ($permissionsMenu) {
            if ($permViewBtn) {
                $permViewBtn->update(['parent_id' => $permissionsMenu->id]);
            }
            if ($permCreateBtn) {
                $permCreateBtn->update(['parent_id' => $permissionsMenu->id]);
            }
            if ($permUpdateBtn) {
                $permUpdateBtn->update(['parent_id' => $permissionsMenu->id]);
            }
            if ($permDeleteBtn) {
                $permDeleteBtn->update(['parent_id' => $permissionsMenu->id]);
            }
            if ($permBatchDeleteBtn) {
                $permBatchDeleteBtn->update(['parent_id' => $permissionsMenu->id]);
            }
        }

        // 更新按钮权限的parent_id - 部门管理
        $deptViewBtn = $permissions->where('name', 'auth.departments.view')->first();
        $deptCreateBtn = $permissions->where('name', 'auth.departments.create')->first();
        $deptUpdateBtn = $permissions->where('name', 'auth.departments.update')->first();
        $deptDeleteBtn = $permissions->where('name', 'auth.departments.delete')->first();
        $deptBatchDeleteBtn = $permissions->where('name', 'auth.departments.batch-delete')->first();
        if ($departmentsMenu) {
            if ($deptViewBtn) {
                $deptViewBtn->update(['parent_id' => $departmentsMenu->id]);
            }
            if ($deptCreateBtn) {
                $deptCreateBtn->update(['parent_id' => $departmentsMenu->id]);
            }
            if ($deptUpdateBtn) {
                $deptUpdateBtn->update(['parent_id' => $departmentsMenu->id]);
            }
            if ($deptDeleteBtn) {
                $deptDeleteBtn->update(['parent_id' => $departmentsMenu->id]);
            }
            if ($deptBatchDeleteBtn) {
                $deptBatchDeleteBtn->update(['parent_id' => $departmentsMenu->id]);
            }
        }
    }

    /**
     * 创建角色
     */
    private function createRoles(): void
    {
        Role::insert([
            [
                'name' => '超级管理员',
                'code' => 'super_admin',
                'description' => '拥有系统所有权限',
                'sort' => 1,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => '管理员',
                'code' => 'admin',
                'description' => '拥有系统管理权限',
                'sort' => 2,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => '普通用户',
                'code' => 'user',
                'description' => '普通用户角色',
                'sort' => 3,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * 创建部门
     */
    private function createDepartments(): void
    {
        Department::insert([
            [
                'name' => '总公司',
                'parent_id' => 0,
                'leader' => '张三',
                'phone' => '13800138000',
                'sort' => 1,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => '技术部',
                'parent_id' => 0,
                'leader' => '李四',
                'phone' => '13800138001',
                'sort' => 1,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => '运营部',
                'parent_id' => 0,
                'leader' => '王五',
                'phone' => '13800138002',
                'sort' => 2,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => '财务部',
                'parent_id' => 0,
                'leader' => '赵六',
                'phone' => '13800138003',
                'sort' => 3,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // 更新parent_id为实际ID
        $departments = Department::all();
        $rootDept = $departments->where('name', '总公司')->first();
        $techDept = $departments->where('name', '技术部')->first();
        $opsDept = $departments->where('name', '运营部')->first();
        $financeDept = $departments->where('name', '财务部')->first();

        if ($rootDept) {
            $techDept->update(['parent_id' => $rootDept->id]);
            $opsDept->update(['parent_id' => $rootDept->id]);
            $financeDept->update(['parent_id' => $rootDept->id]);
        }
    }

    /**
     * 创建用户
     */
    private function createUsers(): void
    {
        $departments = Department::all();
        $techDept = $departments->where('name', '技术部')->first();

        User::insert([
            [
                'username' => 'admin',
                'password' => Hash::make('admin888'),
                'real_name' => '超级管理员',
                'email' => 'admin@example.com',
                'phone' => '13800138888',
                'department_id' => $techDept ? $techDept->id : null,
                'avatar' => null,
                'status' => 1,
                'last_login_at' => null,
                'last_login_ip' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'username' => 'manager',
                'password' => Hash::make('123456789'),
                'real_name' => '部门经理',
                'email' => 'manager@example.com',
                'phone' => '13800138889',
                'department_id' => $techDept ? $techDept->id : null,
                'avatar' => null,
                'status' => 1,
                'last_login_at' => null,
                'last_login_ip' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * 分配权限给角色
     */
    private function assignPermissionsToRole(): void
    {
        $permissions = Permission::all();
        $superAdminRole = Role::where('code', 'super_admin')->first();

        if ($superAdminRole) {
            $permissionIds = $permissions->pluck('id')->toArray();
            $superAdminRole->permissions()->attach($permissionIds);
        }
    }

    /**
     * 分配角色给用户
     */
    private function assignRoleToUser(): void
    {
        $superAdminRole = Role::where('code', 'super_admin')->first();
        $adminRole = Role::where('code', 'admin')->first();

        $adminUser = User::where('username', 'admin')->first();
        $managerUser = User::where('username', 'manager')->first();

        if ($adminUser && $superAdminRole) {
            $adminUser->roles()->attach([$superAdminRole->id]);
        }

        if ($managerUser && $adminRole) {
            $managerUser->roles()->attach([$adminRole->id]);
        }
    }
}
