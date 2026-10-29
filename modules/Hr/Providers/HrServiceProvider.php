<?php

namespace Modules\Hr\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * 人事薪酬模块（Hr）服务提供者
 *
 * F4：工资核算发放（hr_payroll）+ 提成流水（hr_commission_flows）。
 * 表前缀 hr_（工资/提成流水快照）、接口前缀 /admin/hr，
 * 一级菜单「薪酬管理」。提成数据只读引用 Performance 域，不触碰其文件。
 *
 * 路由由 bootstrap/app.php 的 glob（modules 下各模块 routes/admin.php）
 * 自动发现并套内核信封（api + stock.snapshot + /admin 前缀 + admin. 命名），
 * 本 Provider 只负责注册迁移目录。
 */
class HrServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
