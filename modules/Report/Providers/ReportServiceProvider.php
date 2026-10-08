<?php

namespace Modules\Report\Providers;

use Illuminate\Support\ServiceProvider;

class ReportServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // 报表模块自持迁移：查询模版等报表专属表。
        // 报表只读业务表（销售/库存/拜访），不建业务数据的副本，避免双写不一致。
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
