<?php

namespace Modules\VanSales\Providers;

use Illuminate\Support\ServiceProvider;

class VanSalesServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // 车销业务模块自持迁移：要货/拣货/销售/退货/借还换货等全部车销表
        // 结构从本目录起，按 basename 全局排序执行（时间戳晚于历史表，外键顺序无虞）。
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
