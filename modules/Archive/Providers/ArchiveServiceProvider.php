<?php

namespace Modules\Archive\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * 基础档案与开账模块（Archive）服务提供者
 *
 * F6：公司/事业部档案、开账向导（4 步 + Excel 导入 + 完成锁定）、帮助与下载中心。
 * 表前缀 archive_，接口前缀 /admin/data 与 /admin/archive。
 *
 * 路由由 bootstrap/app.php 的 glob 自动发现，本 Provider 只负责注册迁移目录。
 */
class ArchiveServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
