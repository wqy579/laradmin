<?php

namespace Modules\Archive\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Permission;

/**
 * 公司 / 事业部 / 开账向导 / 帮助 / 下载中心菜单权威定义。
 *
 * AuthSeeder 会 Permission::truncate() 整张 auth_permission，只在
 * modules/Archive/database/migrations 下 register_archive_menu /
 * register_opening_wizard_menu / register_help_menu 里注册的菜单与按钮权限
 * 在 db:seed 后全部消失（同 PerformanceMenuSeeder 的说明）。字段照迁移抄。
 *
 * 迁移里 sort 是 max(同级)+1 现算的；这里按迁移跑完后的实际结果写死
 * （company 8 … download 12），保证种子库与迁移库顺序一致、且与执行顺序无关。
 * 按钮沿用迁移的「点号名 + 冒号别名」双写约定。
 *
 * 授权也照迁移：这批只授 super_admin（迁移即如此，Order/Stock 那批才是按父级授权）。
 *
 * 幂等：firstOrCreate 按 name 判重，可反复执行。
 */
class ArchiveMenuSeeder extends Seeder
{
    /** 挂「资料管理」（data 由 BusinessSeeder 建） */
    private const MENUS = [
        ['name' => 'data.company', 'title' => '公司档案', 'path' => '/data/company', 'component' => 'data/company/index', 'sort' => 8],
        ['name' => 'data.division', 'title' => '事业部档案', 'path' => '/data/division', 'component' => 'data/division/index', 'sort' => 9],
        ['name' => 'data.opening', 'title' => '开账向导', 'path' => '/data/opening', 'component' => 'data/opening/index', 'sort' => 10],
        ['name' => 'data.help', 'title' => '帮助中心', 'path' => '/data/help', 'component' => 'data/help/index', 'sort' => 11],
        ['name' => 'data.download', 'title' => '下载中心', 'path' => '/data/download', 'component' => 'data/download/index', 'sort' => 12],
    ];

    /** 按钮权限：父菜单 name / 动作 / 标题；同名另建冒号别名（迁移约定） */
    private const BUTTONS = [
        ['parent' => 'data.company', 'action' => 'view', 'title' => '查看公司档案'],
        ['parent' => 'data.company', 'action' => 'create', 'title' => '新增公司档案'],
        ['parent' => 'data.company', 'action' => 'edit', 'title' => '编辑公司档案'],
        ['parent' => 'data.company', 'action' => 'delete', 'title' => '删除公司档案'],
        ['parent' => 'data.company', 'action' => 'export', 'title' => '导出公司档案'],
        ['parent' => 'data.division', 'action' => 'view', 'title' => '查看事业部档案'],
        ['parent' => 'data.division', 'action' => 'create', 'title' => '新增事业部档案'],
        ['parent' => 'data.division', 'action' => 'edit', 'title' => '编辑事业部档案'],
        ['parent' => 'data.division', 'action' => 'delete', 'title' => '删除事业部档案'],
        ['parent' => 'data.division', 'action' => 'export', 'title' => '导出事业部档案'],
        ['parent' => 'data.opening', 'action' => 'view', 'title' => '查看开账向导'],
        ['parent' => 'data.opening', 'action' => 'edit', 'title' => '编辑开账步骤'],
        ['parent' => 'data.opening', 'action' => 'import', 'title' => '导入期初数据'],
        ['parent' => 'data.opening', 'action' => 'lock', 'title' => '确认开账锁定'],
        ['parent' => 'data.opening', 'action' => 'unlock', 'title' => '反开账解锁'],
        ['parent' => 'data.help', 'action' => 'view', 'title' => '查看帮助文档'],
        ['parent' => 'data.help', 'action' => 'create', 'title' => '新增帮助文档'],
        ['parent' => 'data.help', 'action' => 'edit', 'title' => '编辑帮助文档'],
        ['parent' => 'data.help', 'action' => 'delete', 'title' => '删除帮助文档'],
        ['parent' => 'data.download', 'action' => 'view', 'title' => '查看下载中心'],
        ['parent' => 'data.download', 'action' => 'create', 'title' => '上传下载文件'],
        ['parent' => 'data.download', 'action' => 'edit', 'title' => '编辑下载文件'],
        ['parent' => 'data.download', 'action' => 'delete', 'title' => '删除下载文件'],
        ['parent' => 'data.download', 'action' => 'download', 'title' => '下载文件'],
    ];

    public function run(): void
    {
        $dataParentId = Permission::where('name', 'data')->value('id');
        if (! $dataParentId) {
            return;
        }

        $names = [];

        foreach (self::MENUS as $menu) {
            Permission::firstOrCreate(['name' => $menu['name']], [
                'title' => $menu['title'],
                'type' => 'menu',
                'parent_id' => $dataParentId,
                'path' => $menu['path'],
                'component' => $menu['component'],
                'sort' => $menu['sort'],
                'status' => 1,
                'meta' => ['hidden' => false, 'hiddenBreadcrumb' => false],
            ]);
            $names[] = $menu['name'];
        }

        $buttonSorts = [];
        foreach (self::BUTTONS as $button) {
            $parentId = Permission::where('name', $button['parent'])->value('id') ?? 0;
            $buttonSorts[$button['parent']] = ($buttonSorts[$button['parent']] ?? 0) + 1;

            foreach ($this->names($button['parent'], $button['action']) as $name) {
                Permission::firstOrCreate(['name' => $name], [
                    'title' => $button['title'],
                    'type' => 'button',
                    'parent_id' => $parentId,
                    'path' => $name,
                    'component' => null,
                    'sort' => $buttonSorts[$button['parent']],
                    'status' => 1,
                ]);
                $names[] = $name;
            }
        }

        $this->grantToSuperAdmin($names);
    }

    /** 迁移的 alias()：点号名 + 冒号别名（data.company.view 与 data:company:view） */
    private function names(string $parent, string $action): array
    {
        $dotted = $parent.'.'.$action;

        return [$dotted, str_replace('.', ':', $dotted)];
    }

    private function grantToSuperAdmin(array $names): void
    {
        $roleId = DB::table('auth_role')->where('code', 'super_admin')->value('id');
        if (! $roleId) {
            return;
        }
        $permissionIds = Permission::whereIn('name', $names)->pluck('id');
        foreach ($permissionIds as $permissionId) {
            $granted = DB::table('auth_role_permission')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->exists();
            if (! $granted) {
                DB::table('auth_role_permission')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
