<?php

namespace Modules\Ledger\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * 总账管理模块（Ledger）服务提供者
 *
 * 三机物理隔离：本模块使用 ledger_ 表前缀、/admin/ledger 接口、
 * 「总账管理」一级菜单，独立目录与路由文件，不修改其他机器的文件。
 *
 * 路由由 bootstrap/app.php 的 glob（modules 下各模块 routes/admin.php）
 * 自动发现并套内核信封（api + stock.snapshot + /admin 前缀 + admin. 命名），
 * 本 Provider 只负责注册迁移与种子目录。
 */
class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
