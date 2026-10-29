<?php

namespace Modules\Asset\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * 固定资产模块（Asset）服务提供者
 *
 * D 域：D3 固定资产台账 + 购置单。表前缀 asset_（购置单/资产卡片），
 * 接口前缀 /admin/asset，一级菜单「固定资产」，独立目录与路由文件，
 * 不触碰 Cash（同域另机在做 D2）与 Ledger（凭证服务只调用，不修改）。
 *
 * 路由由 bootstrap/app.php 的 glob（modules 下各模块 routes/admin.php）
 * 自动发现并套内核信封，本 Provider 只负责注册迁移目录。
 */
class AssetServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
