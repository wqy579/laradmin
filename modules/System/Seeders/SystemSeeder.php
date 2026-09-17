<?php

namespace Modules\System\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Permission;
use Modules\System\Models\Config;
use Modules\System\Models\Dictionary;
use Modules\System\Models\DictionaryItem;

class SystemSeeder extends Seeder
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
        DictionaryItem::truncate();
        Dictionary::truncate();
        Config::truncate();

        $this->createSystemPermissions();
        $this->createSystemDictionaries();
        $this->createSystemConfigs();

        $this->command->info('System module data seeded successfully!');

        // 恢复外键约束
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }

    /**
     * 创建系统管理权限节点
     */
    private function createSystemPermissions(): void
    {
        $permissions = [
            // 系统顶级菜单
            [
                'title' => '系统',
                'name' => 'system',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/system',
                'component' => null,
                'meta' => [
                    'icon' => 'ElIconSetting',
                    'hidden' => false,
                    'hiddenBreadcrumb' => false,
                ],
                'sort' => 3,
                'status' => 1,
            ],
            // 系统配置
            [
                'title' => '系统配置',
                'name' => 'system.setting',
                'type' => 'menu',
                'parent_id' => 0, // 稍后更新为系统菜单的ID
                'path' => '/system/setting',
                'component' => 'system/setting/index',
                'meta' => [
                    'icon' => 'AIconSettingFilled',
                    'hidden' => false,
                    'hiddenBreadcrumb' => false,
                ],
                'sort' => 1,
                'status' => 1,
            ],
            [
                'title' => '查看配置',
                'name' => 'system.setting.view',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为系统配置菜单的ID
                'path' => 'admin.setting.index',
                'component' => null,
                'meta' => null,
                'sort' => 1,
                'status' => 1,
            ],
            [
                'title' => '创建配置',
                'name' => 'system.setting.create',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为系统配置菜单的ID
                'path' => 'admin.setting.store',
                'component' => null,
                'meta' => null,
                'sort' => 2,
                'status' => 1,
            ],
            [
                'title' => '编辑配置',
                'name' => 'system.setting.update',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为系统配置菜单的ID
                'path' => 'admin.setting.update',
                'component' => null,
                'meta' => null,
                'sort' => 3,
                'status' => 1,
            ],
            [
                'title' => '删除配置',
                'name' => 'system.setting.delete',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为系统配置菜单的ID
                'path' => 'admin.setting.destroy',
                'component' => null,
                'meta' => null,
                'sort' => 4,
                'status' => 1,
            ],
            [
                'title' => '批量删除配置',
                'name' => 'system.setting.batch-delete',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为系统配置菜单的ID
                'path' => 'admin.setting.batch-delete',
                'component' => null,
                'meta' => null,
                'sort' => 5,
                'status' => 1,
            ],

            // 系统日志
            [
                'title' => '系统日志',
                'name' => 'system.log',
                'type' => 'menu',
                'parent_id' => 0, // 稍后更新为系统菜单的ID
                'path' => '/system/log',
                'component' => 'system/log/index',
                'meta' => [
                    'icon' => 'ElIconDocumentCopy',
                    'hidden' => false,
                    'hiddenBreadcrumb' => false,
                ],
                'sort' => 2,
                'status' => 1,
            ],
            [
                'title' => '查看日志',
                'name' => 'system.log.view',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为系统日志菜单的ID
                'path' => 'admin.log.index',
                'component' => null,
                'meta' => null,
                'sort' => 1,
                'status' => 1,
            ],
            [
                'title' => '删除日志',
                'name' => 'system.log.delete',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为系统日志菜单的ID
                'path' => 'admin.log.destroy',
                'component' => null,
                'meta' => null,
                'sort' => 2,
                'status' => 1,
            ],
            [
                'title' => '批量删除日志',
                'name' => 'system.log.batch-delete',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为系统日志菜单的ID
                'path' => 'admin.log.batch-delete',
                'component' => null,
                'meta' => null,
                'sort' => 3,
                'status' => 1,
            ],
            [
                'title' => '导出日志',
                'name' => 'system.log.export',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为系统日志菜单的ID
                'path' => 'admin.log.export',
                'component' => null,
                'meta' => null,
                'sort' => 4,
                'status' => 1,
            ],

            // 数据字典
            [
                'title' => '数据字典',
                'name' => 'system.dictionary',
                'type' => 'menu',
                'parent_id' => 0, // 稍后更新为系统菜单的ID
                'path' => '/system/dictionary',
                'component' => 'system/dictionary/index',
                'meta' => [
                    'icon' => 'ElIconNotebook',
                    'hidden' => false,
                    'hiddenBreadcrumb' => false,
                ],
                'sort' => 3,
                'status' => 1,
            ],
            [
                'title' => '查看字典',
                'name' => 'system.dictionary.view',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为数据字典菜单的ID
                'path' => 'admin.dictionary.index',
                'component' => null,
                'meta' => null,
                'sort' => 1,
                'status' => 1,
            ],
            [
                'title' => '创建字典',
                'name' => 'system.dictionary.create',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为数据字典菜单的ID
                'path' => 'admin.dictionary.store',
                'component' => null,
                'meta' => null,
                'sort' => 2,
                'status' => 1,
            ],
            [
                'title' => '编辑字典',
                'name' => 'system.dictionary.update',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为数据字典菜单的ID
                'path' => 'admin.dictionary.update',
                'component' => null,
                'meta' => null,
                'sort' => 3,
                'status' => 1,
            ],
            [
                'title' => '删除字典',
                'name' => 'system.dictionary.delete',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为数据字典菜单的ID
                'path' => 'admin.dictionary.destroy',
                'component' => null,
                'meta' => null,
                'sort' => 4,
                'status' => 1,
            ],
            [
                'title' => '批量删除字典',
                'name' => 'system.dictionary.batch-delete',
                'type' => 'button',
                'parent_id' => 0, // 稍后更新为数据字典菜单的ID
                'path' => 'admin.dictionary.batch-delete',
                'component' => null,
                'meta' => null,
                'sort' => 5,
                'status' => 1,
            ],
            // 附件管理
            [
                'title' => '附件管理',
                'name' => 'system.attachment',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/system/attachment',
                'component' => 'system/attachment/index',
                'meta' => [
                    'icon' => 'ElIconPictureFilled',
                    'hidden' => false,
                    'hiddenBreadcrumb' => false,
                ],
                'sort' => 5,
                'status' => 1,
            ],
            [
                'title' => '查看附件',
                'name' => 'system.attachment.list',
                'type' => 'button',
                'parent_id' => 0,
                'path' => 'admin.attachment.index',
                'component' => null,
                'meta' => null,
                'sort' => 1,
                'status' => 1,
            ],
            [
                'title' => '上传附件',
                'name' => 'system.attachment.upload',
                'type' => 'button',
                'parent_id' => 0,
                'path' => 'admin.attachment.upload',
                'component' => null,
                'meta' => null,
                'sort' => 2,
                'status' => 1,
            ],
            [
                'title' => '编辑附件',
                'name' => 'system.attachment.update',
                'type' => 'button',
                'parent_id' => 0,
                'path' => 'admin.attachment.update',
                'component' => null,
                'meta' => null,
                'sort' => 3,
                'status' => 1,
            ],
            [
                'title' => '删除附件',
                'name' => 'system.attachment.delete',
                'type' => 'button',
                'parent_id' => 0,
                'path' => 'admin.attachment.destroy',
                'component' => null,
                'meta' => null,
                'sort' => 4,
                'status' => 1,
            ],
            [
                'title' => '批量删除附件',
                'name' => 'system.attachment.batch-delete',
                'type' => 'button',
                'parent_id' => 0,
                'path' => 'admin.attachment.batch-delete',
                'component' => null,
                'meta' => null,
                'sort' => 5,
                'status' => 1,
            ],

            // 定时调度
            [
                'title' => '定时调度',
                'name' => 'system.scheduled',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/system/scheduled',
                'component' => 'system/scheduled/index',
                'meta' => [
                    'icon' => 'ElIconTimer',
                    'hidden' => false,
                    'hiddenBreadcrumb' => false,
                ],
                'sort' => 7,
                'status' => 1,
            ],
            [
                'title' => '查看调度',
                'name' => 'system.scheduled.view',
                'type' => 'button',
                'parent_id' => 0,
                'path' => 'admin.scheduled.index',
                'component' => null,
                'meta' => null,
                'sort' => 1,
                'status' => 1,
            ],
            [
                'title' => '创建调度',
                'name' => 'system.scheduled.create',
                'type' => 'button',
                'parent_id' => 0,
                'path' => 'admin.scheduled.store',
                'component' => null,
                'meta' => null,
                'sort' => 2,
                'status' => 1,
            ],
            [
                'title' => '编辑调度',
                'name' => 'system.scheduled.update',
                'type' => 'button',
                'parent_id' => 0,
                'path' => 'admin.scheduled.update',
                'component' => null,
                'meta' => null,
                'sort' => 3,
                'status' => 1,
            ],
            [
                'title' => '删除调度',
                'name' => 'system.scheduled.delete',
                'type' => 'button',
                'parent_id' => 0,
                'path' => 'admin.scheduled.destroy',
                'component' => null,
                'meta' => null,
                'sort' => 4,
                'status' => 1,
            ],
            [
                'title' => '批量删除',
                'name' => 'system.scheduled.batch-delete',
                'type' => 'button',
                'parent_id' => 0,
                'path' => 'admin.scheduled.batch-delete',
                'component' => null,
                'meta' => null,
                'sort' => 5,
                'status' => 1,
            ],
            [
                'title' => '执行调度',
                'name' => 'system.scheduled.execute',
                'type' => 'button',
                'parent_id' => 0,
                'path' => 'admin.scheduled.execute',
                'component' => null,
                'meta' => null,
                'sort' => 6,
                'status' => 1,
            ],
            [
                'title' => '启动调度',
                'name' => 'system.scheduled.start',
                'type' => 'button',
                'parent_id' => 0,
                'path' => 'admin.scheduled.start',
                'component' => null,
                'meta' => null,
                'sort' => 7,
                'status' => 1,
            ],
            [
                'title' => '暂停调度',
                'name' => 'system.scheduled.pause',
                'type' => 'button',
                'parent_id' => 0,
                'path' => 'admin.scheduled.pause',
                'component' => null,
                'meta' => null,
                'sort' => 8,
                'status' => 1,
            ],
            [
                'title' => '恢复调度',
                'name' => 'system.scheduled.resume',
                'type' => 'button',
                'parent_id' => 0,
                'path' => 'admin.scheduled.resume',
                'component' => null,
                'meta' => null,
                'sort' => 9,
                'status' => 1,
            ],
            [
                'title' => '停止调度',
                'name' => 'system.scheduled.stop',
                'type' => 'button',
                'parent_id' => 0,
                'path' => 'admin.scheduled.stop',
                'component' => null,
                'meta' => null,
                'sort' => 10,
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

        // 获取系统顶级菜单ID
        $systemMenu = $permissions->where('name', 'system')->first();

        // 获取系统子菜单ID
        $settingMenu = $permissions->where('name', 'system.setting')->first();
        $logMenu = $permissions->where('name', 'system.log')->first();
        $dictionaryMenu = $permissions->where('name', 'system.dictionary')->first();
        $fileMenu = $permissions->where('name', 'system.file')->first();
        $scheduledMenu = $permissions->where('name', 'system.scheduled')->first();

        // 更新系统子菜单的parent_id
        if ($systemMenu) {
            if ($settingMenu) {
                $settingMenu->update(['parent_id' => $systemMenu->id]);
            }
            if ($logMenu) {
                $logMenu->update(['parent_id' => $systemMenu->id]);
            }
            if ($dictionaryMenu) {
                $dictionaryMenu->update(['parent_id' => $systemMenu->id]);
            }
            if ($fileMenu) {
                $fileMenu->update(['parent_id' => $systemMenu->id]);
            }
            $attachmentMenu = $permissions->where('name', 'system.attachment')->first();
            if ($attachmentMenu) {
                $attachmentMenu->update(['parent_id' => $systemMenu->id]);
            }
            if ($scheduledMenu) {
                $scheduledMenu->update(['parent_id' => $systemMenu->id]);
            }
        }

        // 更新按钮权限的parent_id - 系统配置
        $settingViewBtn = $permissions->where('name', 'system.setting.view')->first();
        $settingCreateBtn = $permissions->where('name', 'system.setting.create')->first();
        $settingUpdateBtn = $permissions->where('name', 'system.setting.update')->first();
        $settingDeleteBtn = $permissions->where('name', 'system.setting.delete')->first();
        $settingBatchDeleteBtn = $permissions->where('name', 'system.setting.batch-delete')->first();
        if ($settingMenu) {
            if ($settingViewBtn) {
                $settingViewBtn->update(['parent_id' => $settingMenu->id]);
            }
            if ($settingCreateBtn) {
                $settingCreateBtn->update(['parent_id' => $settingMenu->id]);
            }
            if ($settingUpdateBtn) {
                $settingUpdateBtn->update(['parent_id' => $settingMenu->id]);
            }
            if ($settingDeleteBtn) {
                $settingDeleteBtn->update(['parent_id' => $settingMenu->id]);
            }
            if ($settingBatchDeleteBtn) {
                $settingBatchDeleteBtn->update(['parent_id' => $settingMenu->id]);
            }
        }

        // 更新按钮权限的parent_id - 系统日志
        $logViewBtn = $permissions->where('name', 'system.log.view')->first();
        $logDeleteBtn = $permissions->where('name', 'system.log.delete')->first();
        $logBatchDeleteBtn = $permissions->where('name', 'system.log.batch-delete')->first();
        $logExportBtn = $permissions->where('name', 'system.log.export')->first();
        if ($logMenu) {
            if ($logViewBtn) {
                $logViewBtn->update(['parent_id' => $logMenu->id]);
            }
            if ($logDeleteBtn) {
                $logDeleteBtn->update(['parent_id' => $logMenu->id]);
            }
            if ($logBatchDeleteBtn) {
                $logBatchDeleteBtn->update(['parent_id' => $logMenu->id]);
            }
            if ($logExportBtn) {
                $logExportBtn->update(['parent_id' => $logMenu->id]);
            }
        }

        // 更新按钮权限的parent_id - 数据字典
        $dictViewBtn = $permissions->where('name', 'system.dictionary.view')->first();
        $dictCreateBtn = $permissions->where('name', 'system.dictionary.create')->first();
        $dictUpdateBtn = $permissions->where('name', 'system.dictionary.update')->first();
        $dictDeleteBtn = $permissions->where('name', 'system.dictionary.delete')->first();
        $dictBatchDeleteBtn = $permissions->where('name', 'system.dictionary.batch-delete')->first();
        if ($dictionaryMenu) {
            if ($dictViewBtn) {
                $dictViewBtn->update(['parent_id' => $dictionaryMenu->id]);
            }
            if ($dictCreateBtn) {
                $dictCreateBtn->update(['parent_id' => $dictionaryMenu->id]);
            }
            if ($dictUpdateBtn) {
                $dictUpdateBtn->update(['parent_id' => $dictionaryMenu->id]);
            }
            if ($dictDeleteBtn) {
                $dictDeleteBtn->update(['parent_id' => $dictionaryMenu->id]);
            }
            if ($dictBatchDeleteBtn) {
                $dictBatchDeleteBtn->update(['parent_id' => $dictionaryMenu->id]);
            }
        }

        // 更新按钮权限的parent_id - 文件管理
        $fileListBtn = $permissions->where('name', 'system.file.list')->first();
        $fileUploadBtn = $permissions->where('name', 'system.file.upload')->first();
        $fileUpdateBtn = $permissions->where('name', 'system.file.update')->first();
        $fileDeleteBtn = $permissions->where('name', 'system.file.delete')->first();
        $fileBatchDeleteBtn = $permissions->where('name', 'system.file.batch-delete')->first();
        $fileDownloadBtn = $permissions->where('name', 'system.file.download')->first();
        if ($fileMenu) {
            if ($fileListBtn) {
                $fileListBtn->update(['parent_id' => $fileMenu->id]);
            }
            if ($fileUploadBtn) {
                $fileUploadBtn->update(['parent_id' => $fileMenu->id]);
            }
            if ($fileUpdateBtn) {
                $fileUpdateBtn->update(['parent_id' => $fileMenu->id]);
            }
            if ($fileDeleteBtn) {
                $fileDeleteBtn->update(['parent_id' => $fileMenu->id]);
            }
            if ($fileBatchDeleteBtn) {
                $fileBatchDeleteBtn->update(['parent_id' => $fileMenu->id]);
            }
            if ($fileDownloadBtn) {
                $fileDownloadBtn->update(['parent_id' => $fileMenu->id]);
            }
        }

        // 更新按钮权限的parent_id - 附件管理
        $attachmentMenu = $permissions->where('name', 'system.attachment')->first();
        $attachmentButtons = [
            'system.attachment.list' => 'system.attachment.list',
            'system.attachment.upload' => 'system.attachment.upload',
            'system.attachment.update' => 'system.attachment.update',
            'system.attachment.delete' => 'system.attachment.delete',
            'system.attachment.batch-delete' => 'system.attachment.batch-delete',
        ];
        if ($attachmentMenu) {
            foreach ($attachmentButtons as $name) {
                $btn = $permissions->where('name', $name)->first();
                if ($btn) {
                    $btn->update(['parent_id' => $attachmentMenu->id]);
                }
            }
        }

        // 更新按钮权限的parent_id - 定时调度
        $scheduledViewBtn = $permissions->where('name', 'system.scheduled.view')->first();
        $scheduledCreateBtn = $permissions->where('name', 'system.scheduled.create')->first();
        $scheduledUpdateBtn = $permissions->where('name', 'system.scheduled.update')->first();
        $scheduledDeleteBtn = $permissions->where('name', 'system.scheduled.delete')->first();
        $scheduledBatchDeleteBtn = $permissions->where('name', 'system.scheduled.batch-delete')->first();
        $scheduledExecuteBtn = $permissions->where('name', 'system.scheduled.execute')->first();
        $scheduledStartBtn = $permissions->where('name', 'system.scheduled.start')->first();
        $scheduledPauseBtn = $permissions->where('name', 'system.scheduled.pause')->first();
        $scheduledResumeBtn = $permissions->where('name', 'system.scheduled.resume')->first();
        $scheduledStopBtn = $permissions->where('name', 'system.scheduled.stop')->first();
        if ($scheduledMenu) {
            if ($scheduledViewBtn) {
                $scheduledViewBtn->update(['parent_id' => $scheduledMenu->id]);
            }
            if ($scheduledCreateBtn) {
                $scheduledCreateBtn->update(['parent_id' => $scheduledMenu->id]);
            }
            if ($scheduledUpdateBtn) {
                $scheduledUpdateBtn->update(['parent_id' => $scheduledMenu->id]);
            }
            if ($scheduledDeleteBtn) {
                $scheduledDeleteBtn->update(['parent_id' => $scheduledMenu->id]);
            }
            if ($scheduledBatchDeleteBtn) {
                $scheduledBatchDeleteBtn->update(['parent_id' => $scheduledMenu->id]);
            }
            if ($scheduledExecuteBtn) {
                $scheduledExecuteBtn->update(['parent_id' => $scheduledMenu->id]);
            }
            if ($scheduledStartBtn) {
                $scheduledStartBtn->update(['parent_id' => $scheduledMenu->id]);
            }
            if ($scheduledPauseBtn) {
                $scheduledPauseBtn->update(['parent_id' => $scheduledMenu->id]);
            }
            if ($scheduledResumeBtn) {
                $scheduledResumeBtn->update(['parent_id' => $scheduledMenu->id]);
            }
            if ($scheduledStopBtn) {
                $scheduledStopBtn->update(['parent_id' => $scheduledMenu->id]);
            }
        }

    }

    /**
     * 创建系统字典
     */
    private function createSystemDictionaries(): void
    {
        // 创建字典类型
        $dictionary = [
            [
                'name' => '用户状态',
                'code' => 'user_status',
                'description' => '用户账号状态',
                'value_type' => 'number',
                'sort' => 1,
                'status' => 1,
            ],
            [
                'name' => '性别',
                'code' => 'gender',
                'description' => '用户性别',
                'value_type' => 'number',
                'sort' => 2,
                'status' => 1,
            ],
            [
                'name' => '角色状态',
                'code' => 'role_status',
                'description' => '角色启用状态',
                'value_type' => 'number',
                'sort' => 3,
                'status' => 1,
            ],
            [
                'name' => '字典状态',
                'code' => 'dictionary_status',
                'description' => '数据字典状态',
                'value_type' => 'number',
                'sort' => 4,
                'status' => 1,
            ],
            [
                'name' => '调度状态',
                'code' => 'scheduled_status',
                'description' => '定时调度任务状态',
                'value_type' => 'string',
                'sort' => 5,
                'status' => 1,
            ],
            [
                'name' => '调度类型',
                'code' => 'scheduled_type',
                'description' => '定时调度任务类型',
                'value_type' => 'string',
                'sort' => 6,
                'status' => 1,
            ],
            [
                'name' => '日志类型',
                'code' => 'log_type',
                'description' => '系统日志类型',
                'value_type' => 'string',
                'sort' => 6,
                'status' => 1,
            ],
            [
                'name' => '是否',
                'code' => 'yes_no',
                'description' => '是否选项',
                'value_type' => 'boolean',
                'sort' => 7,
                'status' => 1,
            ],
            [
                'name' => '通知类型',
                'code' => 'notification_type',
                'description' => '系统通知的类型，控制显示样式',
                'value_type' => 'string',
                'sort' => 8,
                'status' => 1,
            ],
            [
                'name' => '通知分类',
                'code' => 'notification_category',
                'description' => '系统通知的业务分类，用于消息分组',
                'value_type' => 'string',
                'sort' => 9,
                'status' => 1,
            ],
            [
                'name' => '字段类型',
                'code' => 'field_type',
                'description' => '动态字段/区块可用类型目录',
                'value_type' => 'string',
                'sort' => 10,
                'status' => 1,
            ],
        ];

        foreach ($dictionary as $dictionary) {
            $dict = Dictionary::create($dictionary);
            $this->createDictionaryItems($dict);
        }
    }

    /**
     * 创建字典项
     */
    private function createDictionaryItems(Dictionary $dictionary): void
    {
        $items = [];

        switch ($dictionary->code) {
            case 'user_status':
                $items = [
                    ['label' => '正常', 'value' => 1, 'sort' => 1, 'status' => 1],
                    ['label' => '禁用', 'value' => 0, 'sort' => 2, 'status' => 1],
                ];
                break;

            case 'gender':
                $items = [
                    ['label' => '男', 'value' => 1, 'sort' => 1, 'status' => 1],
                    ['label' => '女', 'value' => 2, 'sort' => 2, 'status' => 1],
                    ['label' => '保密', 'value' => 0, 'sort' => 3, 'status' => 1],
                ];
                break;

            case 'role_status':
                $items = [
                    ['label' => '启用', 'value' => 1, 'sort' => 1, 'status' => 1],
                    ['label' => '禁用', 'value' => 0, 'sort' => 2, 'status' => 1],
                ];
                break;

            case 'dictionary_status':
                $items = [
                    ['label' => '启用', 'value' => 1, 'sort' => 1, 'status' => 1],
                    ['label' => '禁用', 'value' => 0, 'sort' => 2, 'status' => 1],
                ];
                break;

            case 'scheduled_status':
                $items = [
                    ['label' => '空闲', 'value' => 'idle', 'color' => '#409EFF', 'sort' => 1, 'status' => 1],
                    ['label' => '运行中', 'value' => 'running', 'color' => '#67C23A', 'sort' => 2, 'status' => 1],
                    ['label' => '已暂停', 'value' => 'paused', 'color' => '#E6A23C', 'sort' => 3, 'status' => 1],
                    ['label' => '已停止', 'value' => 'stopped', 'color' => '#909399', 'sort' => 4, 'status' => 1],
                    ['label' => '异常', 'value' => 'error', 'color' => '#F56C6C', 'sort' => 5, 'status' => 1],
                ];
                break;

            case 'scheduled_type':
                $items = [
                    ['label' => 'Artisan命令', 'value' => 'artisan', 'sort' => 1, 'status' => 1],
                    ['label' => '队列任务', 'value' => 'job', 'sort' => 2, 'status' => 1],
                    ['label' => 'Shell命令', 'value' => 'shell', 'sort' => 3, 'status' => 1],
                ];
                break;

            case 'log_type':
                $items = [
                    ['label' => '登录日志', 'value' => 'login', 'sort' => 1, 'status' => 1],
                    ['label' => '操作日志', 'value' => 'operation', 'sort' => 2, 'status' => 1],
                    ['label' => '异常日志', 'value' => 'error', 'sort' => 3, 'status' => 1],
                    ['label' => '系统日志', 'value' => 'system', 'sort' => 4, 'status' => 1],
                ];
                break;

            case 'yes_no':
                $items = [
                    ['label' => '是', 'value' => 1, 'sort' => 1, 'status' => 1],
                    ['label' => '否', 'value' => 0, 'sort' => 2, 'status' => 1],
                ];
                break;

            case 'notification_type':
                $items = [
                    ['label' => '信息', 'value' => 'info', 'color' => '#409EFF', 'sort' => 1, 'status' => 1],
                    ['label' => '成功', 'value' => 'success', 'color' => '#67C23A', 'sort' => 2, 'status' => 1],
                    ['label' => '警告', 'value' => 'warning', 'color' => '#E6A23C', 'sort' => 3, 'status' => 1],
                    ['label' => '错误', 'value' => 'error', 'color' => '#F56C6C', 'sort' => 4, 'status' => 1],
                    ['label' => '任务', 'value' => 'task', 'color' => '#409EFF', 'sort' => 5, 'status' => 1],
                    ['label' => '系统', 'value' => 'system', 'color' => '#909399', 'sort' => 6, 'status' => 1],
                ];
                break;

            case 'notification_category':
                $items = [
                    ['label' => '系统通知', 'value' => 'system', 'color' => '#409EFF', 'sort' => 1, 'status' => 1],
                    ['label' => '任务通知', 'value' => 'task', 'color' => '#67C23A', 'sort' => 2, 'status' => 1],
                    ['label' => '消息', 'value' => 'message', 'color' => '#9B59B6', 'sort' => 3, 'status' => 1],
                    ['label' => '提醒', 'value' => 'reminder', 'color' => '#E6A23C', 'sort' => 4, 'status' => 1],
                    ['label' => '公告', 'value' => 'announcement', 'color' => '#F56C6C', 'sort' => 5, 'status' => 1],
                ];
                break;

            case 'field_type':
                $items = [
                    ['label' => '文本', 'value' => 'text', 'sort' => 1, 'status' => 1],
                    ['label' => '文本域', 'value' => 'textarea', 'sort' => 2, 'status' => 1],
                    ['label' => '富文本', 'value' => 'richtext', 'sort' => 3, 'status' => 1],
                    ['label' => '数字', 'value' => 'number', 'sort' => 4, 'status' => 1],
                    ['label' => '单图上传', 'value' => 'single_image', 'sort' => 5, 'status' => 1],
                    ['label' => '多图上传', 'value' => 'multi_image', 'sort' => 6, 'status' => 1],
                    ['label' => '文件', 'value' => 'file', 'sort' => 7, 'status' => 1],
                    ['label' => '日期', 'value' => 'date', 'sort' => 8, 'status' => 1],
                    ['label' => '下拉选择', 'value' => 'select', 'sort' => 9, 'status' => 1],
                    ['label' => '多选', 'value' => 'multiselect', 'sort' => 10, 'status' => 1],
                    ['label' => '开关', 'value' => 'switch', 'sort' => 11, 'status' => 1],
                ];
                break;

        }

        foreach ($items as $item) {
            $dictionary->items()->create($item);
        }
    }

    /**
     * 创建系统配置
     */
    private function createSystemConfigs(): void
    {
        // 先创建一级分组
        $groups = [
            ['group' => 'site', 'key' => 'group_site', 'name' => '网站设置', 'sort' => 1],
            ['group' => 'system', 'key' => 'group_system', 'name' => '系统设置', 'sort' => 2],
            ['group' => 'storage', 'key' => 'group_storage', 'name' => '存储与上传', 'sort' => 3],
        ];

        $groupIds = [];
        foreach ($groups as $group) {
            $record = Config::create([
                'parent_id' => null,
                'item_type' => 'group',
                'group' => $group['group'],
                'key' => $group['key'],
                'name' => $group['name'],
                'type' => 'string',
                'sort' => $group['sort'],
                'is_system' => true,
                'status' => 1,
            ]);
            $groupIds[$group['group']] = $record->id;
        }

        // 创建二级子分组
        $subGroups = [
            ['parent_key' => 'site', 'group' => 'site', 'key' => 'group_site_basic', 'name' => '基础信息', 'sort' => 1],
            ['parent_key' => 'site', 'group' => 'site', 'key' => 'group_site_seo', 'name' => 'SEO设置', 'sort' => 2],
            ['parent_key' => 'system', 'group' => 'system', 'key' => 'group_system_basic', 'name' => '基本设置', 'sort' => 1],
            ['parent_key' => 'system', 'group' => 'system', 'key' => 'group_system_auth', 'name' => '认证设置', 'sort' => 2],
            ['parent_key' => 'storage', 'group' => 'storage', 'key' => 'group_storage_driver', 'name' => '存储驱动', 'sort' => 1],
            ['parent_key' => 'storage', 'group' => 'storage', 'key' => 'group_storage_upload', 'name' => '上传设置', 'sort' => 2],
        ];

        $subGroupIds = [];
        foreach ($subGroups as $sub) {
            $record = Config::create([
                'parent_id' => $groupIds[$sub['parent_key']],
                'item_type' => 'group',
                'group' => $sub['group'],
                'key' => $sub['key'],
                'name' => $sub['name'],
                'type' => 'string',
                'sort' => $sub['sort'],
                'is_system' => true,
                'status' => 1,
            ]);
            $subGroupIds[$sub['key']] = $record->id;
        }

        // 创建三级分组（存储驱动下的各驱动分类）
        $driverGroups = [
            ['parent_key' => 'group_storage_driver', 'group' => 'storage', 'key' => 'group_driver_s3', 'name' => 'Amazon S3', 'sort' => 1],
            ['parent_key' => 'group_storage_driver', 'group' => 'storage', 'key' => 'group_driver_minio', 'name' => 'MinIO', 'sort' => 2],
            ['parent_key' => 'group_storage_driver', 'group' => 'storage', 'key' => 'group_driver_oss', 'name' => '阿里云OSS', 'sort' => 3],
            ['parent_key' => 'group_storage_driver', 'group' => 'storage', 'key' => 'group_driver_rustfs', 'name' => 'RustFS', 'sort' => 4],
        ];

        foreach ($driverGroups as $dg) {
            $record = Config::create([
                'parent_id' => $subGroupIds[$dg['parent_key']],
                'item_type' => 'group',
                'group' => $dg['group'],
                'key' => $dg['key'],
                'name' => $dg['name'],
                'type' => 'string',
                'sort' => $dg['sort'],
                'is_system' => true,
                'status' => 1,
            ]);
            $subGroupIds[$dg['key']] = $record->id;
        }

        // 创建配置项
        $configs = [
            // === 网站设置 > 基础信息 ===
            [
                'parent_id' => $subGroupIds['group_site_basic'],
                'item_type' => 'config',
                'group' => 'site',
                'key' => 'site_name',
                'name' => '网站名称',
                'value' => 'LarAdmin',
                'type' => 'string',
                'description' => '系统显示的网站名称',
                'sort' => 1,
                'is_system' => true,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_site_basic'],
                'item_type' => 'config',
                'group' => 'site',
                'key' => 'site_logo',
                'name' => '网站Logo',
                'value' => '',
                'type' => 'file',
                'description' => '系统Logo图片地址',
                'sort' => 2,
                'is_system' => true,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_site_basic'],
                'item_type' => 'config',
                'group' => 'site',
                'key' => 'site_favicon',
                'name' => '网站图标',
                'value' => '',
                'type' => 'file',
                'description' => '浏览器标签页图标（建议尺寸32x32）',
                'sort' => 3,
                'is_system' => true,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_site_basic'],
                'item_type' => 'config',
                'group' => 'site',
                'key' => 'site_copyright',
                'name' => '版权信息',
                'value' => '© 2024 LarAdmin',
                'type' => 'string',
                'description' => '网站底部版权信息',
                'sort' => 4,
                'is_system' => true,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_site_basic'],
                'item_type' => 'config',
                'group' => 'site',
                'key' => 'site_icp',
                'name' => '备案号',
                'value' => '',
                'type' => 'string',
                'description' => 'ICP备案号',
                'sort' => 5,
                'is_system' => true,
                'status' => 1,
            ],

            // === 网站设置 > SEO设置 ===
            [
                'parent_id' => $subGroupIds['group_site_seo'],
                'item_type' => 'config',
                'group' => 'site',
                'key' => 'site_description',
                'name' => '网站描述',
                'value' => '',
                'type' => 'text',
                'description' => '网站SEO描述',
                'sort' => 1,
                'is_system' => true,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_site_seo'],
                'item_type' => 'config',
                'group' => 'site',
                'key' => 'site_keywords',
                'name' => '网站关键词',
                'value' => '',
                'type' => 'string',
                'description' => '网站SEO关键词，多个用逗号分隔',
                'sort' => 2,
                'is_system' => true,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_site_seo'],
                'item_type' => 'config',
                'group' => 'site',
                'key' => 'site_analytics',
                'name' => '统计代码',
                'value' => '',
                'type' => 'text',
                'description' => '网站统计代码（如百度统计、Google Analytics）',
                'sort' => 3,
                'is_system' => false,
                'status' => 1,
            ],

            // === 系统设置 > 基本设置 ===
            [
                'parent_id' => $subGroupIds['group_system_basic'],
                'item_type' => 'config',
                'group' => 'system',
                'key' => 'user_default_avatar',
                'name' => '默认头像',
                'value' => '',
                'type' => 'file',
                'description' => '用户默认头像地址',
                'sort' => 1,
                'is_system' => true,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_system_basic'],
                'item_type' => 'config',
                'group' => 'system',
                'key' => 'system_timezone',
                'name' => '系统时区',
                'value' => 'Asia/Shanghai',
                'type' => 'select',
                'options' => json_encode([
                    ['label' => 'UTC', 'value' => 'UTC'],
                    ['label' => 'Asia/Shanghai', 'value' => 'Asia/Shanghai'],
                    ['label' => 'Asia/Tokyo', 'value' => 'Asia/Tokyo'],
                    ['label' => 'America/New_York', 'value' => 'America/New_York'],
                    ['label' => 'Europe/London', 'value' => 'Europe/London'],
                ]),
                'description' => '系统默认时区',
                'sort' => 2,
                'is_system' => true,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_system_basic'],
                'item_type' => 'config',
                'group' => 'system',
                'key' => 'system_language',
                'name' => '系统语言',
                'value' => 'zh-CN',
                'type' => 'select',
                'options' => json_encode([
                    ['label' => '简体中文', 'value' => 'zh-CN'],
                    ['label' => 'English', 'value' => 'en'],
                ]),
                'description' => '系统默认语言',
                'sort' => 3,
                'is_system' => true,
                'status' => 1,
            ],

            // === 系统设置 > 认证设置 ===
            [
                'parent_id' => $subGroupIds['group_system_auth'],
                'item_type' => 'config',
                'group' => 'system',
                'key' => 'enable_register',
                'name' => '开启注册',
                'value' => '1',
                'type' => 'boolean',
                'description' => '是否开放用户注册',
                'sort' => 1,
                'is_system' => true,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_system_auth'],
                'item_type' => 'config',
                'group' => 'system',
                'key' => 'jwt_ttl',
                'name' => 'Token有效期',
                'value' => '60',
                'default_value' => '60',
                'type' => 'number',
                'description' => 'JWT Token 有效期（分钟）',
                'sort' => 2,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_system_auth'],
                'item_type' => 'config',
                'group' => 'system',
                'key' => 'login_max_attempts',
                'name' => '登录失败锁定次数',
                'value' => '5',
                'default_value' => '5',
                'type' => 'number',
                'description' => '连续登录失败多少次后锁定账号',
                'sort' => 3,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_system_auth'],
                'item_type' => 'config',
                'group' => 'system',
                'key' => 'login_lock_minutes',
                'name' => '锁定时长',
                'value' => '15',
                'default_value' => '15',
                'type' => 'number',
                'description' => '账号锁定时长（分钟）',
                'sort' => 4,
                'is_system' => false,
                'status' => 1,
            ],

            // === 存储与上传 > 存储驱动 ===
            [
                'parent_id' => $subGroupIds['group_storage_driver'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'storage_driver',
                'name' => '存储驱动',
                'value' => 'local',
                'type' => 'select',
                'options' => json_encode([
                    ['label' => '本地存储', 'value' => 'local'],
                    ['label' => 'Amazon S3', 'value' => 's3'],
                    ['label' => 'MinIO', 'value' => 'minio'],
                    ['label' => '阿里云OSS', 'value' => 'oss'],
                    ['label' => 'RustFS', 'value' => 'rustfs'],
                ]),
                'description' => '选择文件存储驱动',
                'sort' => 1,
                'is_system' => true,
                'status' => 1,
            ],

            // === 存储驱动 > Amazon S3 ===
            [
                'parent_id' => $subGroupIds['group_driver_s3'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 's3_endpoint',
                'name' => 'Endpoint',
                'value' => '',
                'type' => 'string',
                'description' => 'S3 兼容的 Endpoint 地址',
                'sort' => 1,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_s3'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 's3_region',
                'name' => 'Region',
                'value' => 'us-east-1',
                'type' => 'string',
                'description' => 'S3 区域',
                'sort' => 2,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_s3'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 's3_bucket',
                'name' => 'Bucket',
                'value' => '',
                'type' => 'string',
                'description' => '存储桶名称',
                'sort' => 3,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_s3'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 's3_access_key',
                'name' => 'Access Key',
                'value' => '',
                'type' => 'string',
                'description' => '访问密钥 ID',
                'sort' => 4,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_s3'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 's3_secret_key',
                'name' => 'Secret Key',
                'value' => '',
                'type' => 'string',
                'description' => '访问密钥 Secret',
                'sort' => 5,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_s3'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 's3_url',
                'name' => '自定义URL',
                'value' => '',
                'type' => 'string',
                'description' => '文件访问的自定义 URL 前缀',
                'sort' => 6,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_s3'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 's3_use_path_style',
                'name' => '使用路径风格',
                'value' => '0',
                'type' => 'boolean',
                'description' => 'MinIO 等兼容存储建议开启',
                'sort' => 7,
                'is_system' => false,
                'status' => 1,
            ],

            // === 存储驱动 > MinIO ===
            [
                'parent_id' => $subGroupIds['group_driver_minio'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'minio_endpoint',
                'name' => 'Endpoint',
                'value' => '',
                'type' => 'string',
                'description' => 'MinIO 服务地址，如 http://127.0.0.1:9000',
                'sort' => 1,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_minio'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'minio_access_key',
                'name' => 'Access Key',
                'value' => '',
                'type' => 'string',
                'description' => '访问密钥 ID',
                'sort' => 2,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_minio'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'minio_secret_key',
                'name' => 'Secret Key',
                'value' => '',
                'type' => 'string',
                'description' => '访问密钥 Secret',
                'sort' => 3,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_minio'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'minio_bucket',
                'name' => 'Bucket',
                'value' => '',
                'type' => 'string',
                'description' => '存储桶名称',
                'sort' => 4,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_minio'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'minio_url',
                'name' => '自定义URL',
                'value' => '',
                'type' => 'string',
                'description' => '文件访问的自定义 URL 前缀',
                'sort' => 5,
                'is_system' => false,
                'status' => 1,
            ],

            // === 存储驱动 > RustFS ===
            [
                'parent_id' => $subGroupIds['group_driver_rustfs'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'rustfs_endpoint',
                'name' => 'Endpoint',
                'value' => '',
                'type' => 'string',
                'description' => 'RustFS 服务地址，如 http://127.0.0.1:9000',
                'sort' => 1,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_rustfs'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'rustfs_access_key',
                'name' => 'Access Key',
                'value' => '',
                'type' => 'string',
                'description' => '访问密钥 ID',
                'sort' => 2,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_rustfs'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'rustfs_secret_key',
                'name' => 'Secret Key',
                'value' => '',
                'type' => 'string',
                'description' => '访问密钥 Secret',
                'sort' => 3,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_rustfs'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'rustfs_bucket',
                'name' => 'Bucket',
                'value' => '',
                'type' => 'string',
                'description' => '存储桶名称',
                'sort' => 4,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_rustfs'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'rustfs_region',
                'name' => 'Region',
                'value' => 'us-east-1',
                'type' => 'string',
                'description' => '区域',
                'sort' => 5,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_rustfs'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'rustfs_url',
                'name' => '自定义URL',
                'value' => '',
                'type' => 'string',
                'description' => '文件访问的自定义 URL 前缀',
                'sort' => 6,
                'is_system' => false,
                'status' => 1,
            ],

            // === 存储驱动 > 阿里云OSS ===
            [
                'parent_id' => $subGroupIds['group_driver_oss'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'oss_access_key',
                'name' => 'Access Key ID',
                'value' => '',
                'type' => 'string',
                'description' => '阿里云 AccessKey ID',
                'sort' => 1,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_oss'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'oss_access_secret',
                'name' => 'Access Key Secret',
                'value' => '',
                'type' => 'string',
                'description' => '阿里云 AccessKey Secret',
                'sort' => 2,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_oss'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'oss_bucket',
                'name' => 'Bucket',
                'value' => '',
                'type' => 'string',
                'description' => '存储空间名称',
                'sort' => 3,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_oss'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'oss_endpoint',
                'name' => 'Endpoint',
                'value' => '',
                'type' => 'string',
                'description' => '访问域名，如 oss-cn-hangzhou.aliyuncs.com',
                'sort' => 4,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_driver_oss'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'oss_url',
                'name' => '自定义域名',
                'value' => '',
                'type' => 'string',
                'description' => '文件访问的自定义域名',
                'sort' => 5,
                'is_system' => false,
                'status' => 1,
            ],

            // === 存储与上传 > 上传设置 ===
            [
                'parent_id' => $subGroupIds['group_storage_upload'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'upload_max_size',
                'name' => '上传最大限制',
                'value' => '10',
                'type' => 'number',
                'description' => '文件上传最大限制（MB）',
                'sort' => 1,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_storage_upload'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'upload_allowed_types',
                'name' => '允许上传类型',
                'value' => 'jpg,jpeg,png,gif,webp,svg,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,rar',
                'type' => 'string',
                'description' => '允许上传的文件扩展名，多个用逗号分隔',
                'sort' => 2,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_storage_upload'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'upload_image_max_width',
                'name' => '图片最大宽度',
                'value' => '1920',
                'type' => 'number',
                'description' => '上传图片自动缩放最大宽度（px），0为不限制',
                'sort' => 3,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_storage_upload'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'upload_image_quality',
                'name' => '图片压缩质量',
                'value' => '80',
                'type' => 'number',
                'description' => '图片压缩质量（1-100）',
                'sort' => 4,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_storage_upload'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'upload_watermark',
                'name' => '图片水印',
                'value' => '0',
                'type' => 'boolean',
                'description' => '上传图片是否自动添加水印',
                'sort' => 5,
                'is_system' => false,
                'status' => 1,
            ],
            [
                'parent_id' => $subGroupIds['group_storage_upload'],
                'item_type' => 'config',
                'group' => 'storage',
                'key' => 'upload_watermark_image',
                'name' => '水印图片',
                'value' => '',
                'type' => 'file',
                'description' => '水印图片地址',
                'sort' => 6,
                'is_system' => false,
                'status' => 1,
            ],
        ];

        foreach ($configs as $config) {
            Config::create($config);
        }
    }
}
