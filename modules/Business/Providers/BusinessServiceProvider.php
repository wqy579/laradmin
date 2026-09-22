<?php

namespace Modules\Business\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Business\Console\Commands\SyncCostPrices;

class BusinessServiceProvider extends ServiceProvider
{
    protected array $commands = [
        SyncCostPrices::class,
    ];

    public function register(): void
    {
        $this->commands($this->commands);
    }

    public function boot(): void
    {
        // 本模块是 2026-10 模块化拆分后的「业务残部」，装三样东西：
        //
        // 1. 残部领域代码：员工 / 考勤 / 费用（Modules\Business\Models、Services、Http\Controllers）。
        //    库存 → Modules\Stock，订单 → Modules\Order。要继续细分可再抽 Hr、Finance。
        //
        // 2. 菜单与主数据 Seeder：BusinessSeeder 写整个后台菜单树（依赖 Auth 的 Permission），
        //    BusinessDataSeeder 写商品/仓库/客户等主数据。两者都跨领域，不属于 Stock/Order 任一方。
        //
        // 3. 历史迁移：2026-09 之前库存、订单、财务的表结构由少数「混合表」迁移一次性创建
        //    （create_business_tables 一个文件跨 customers/products/units/vehicles/warehouses，
        //    create_transaction_tables 一个文件跨订单+库存+出入库）。按领域拆只能重写已执行的
        //    迁移、污染既有库的 migrations 记录，因此全部留在本目录。
        //    Stock/Order 各自有 database/migrations，承接 2026-10 之后的**新增**表。
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
