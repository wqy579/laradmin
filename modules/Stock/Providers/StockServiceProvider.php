<?php

namespace Modules\Stock\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Stock\Console\Commands\StockSnapshot;

class StockServiceProvider extends ServiceProvider
{
    protected array $commands = [
        StockSnapshot::class,
    ];

    public function register(): void
    {
        $this->commands($this->commands);
    }

    public function boot(): void
    {
        // 库存模块自持迁移：注册后普通 migrate 也会执行本目录。
        //
        // ⚠️ 历史包袱说明：本项目 2026-09 之前的库存相关表结构
        // （products/warehouses/stocks/stock_ins/stock_outs/transfers/liankai_stock_checks 等）
        // 是由 modules/Business/database/migrations 下的「混合表」迁移一次性创建的
        // （create_business_tables / create_transaction_tables 一个文件里跨多个领域建表），
        // 无法按领域拆分而不重写已执行的迁移，因此**保留在 Business 模块内**。
        //
        // 本目录是库存领域**新增**表结构（2026-10 及以后）的落脚点，按领域就近放置。
        // 迁移按 basename 全局排序执行，时间戳天然晚于历史迁移，外键顺序不会错。
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
