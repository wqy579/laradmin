<?php

namespace Modules\Agreement\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * 协议管理模块（Agreement）服务提供者
 *
 * F4：客户协议 CRUD（agr_agreement）+ 陈列协议统计（兑现率进度条）。
 * 表前缀 agr_（协议）、接口前缀 /admin/agr，一级菜单「协议管理」。
 *
 * 路由由 bootstrap/app.php 的 glob 自动发现，本 Provider 只负责注册迁移目录。
 */
class AgreementServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
