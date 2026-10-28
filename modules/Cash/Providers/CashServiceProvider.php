<?php

namespace Modules\Cash\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * 出纳资金模块（Cash）服务提供者
 *
 * D 域：现金账户收支查询（D1）+ 刷卡收款记录（D1）。
 * 表前缀 cash_（账户/流水/刷卡）、接口前缀 /admin/cash，
 * 一级菜单「出纳管理」，独立目录与路由文件，不触碰其他域的文件。
 *
 * 路由由 bootstrap/app.php 的 glob（modules 下各模块 routes/admin.php）
 * 自动发现并套内核信封（api + stock.snapshot + /admin 前缀 + admin. 命名），
 * 本 Provider 只负责注册迁移目录。
 */
class CashServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
