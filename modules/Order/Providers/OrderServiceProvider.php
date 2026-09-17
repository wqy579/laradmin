<?php

namespace Modules\Order\Providers;

use Illuminate\Support\ServiceProvider;

class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        // 订单模块自持迁移：注册后普通 migrate 也会执行本目录。
        //
        // ⚠️ 历史包袱说明：purchase_orders/sales_orders/returns/deliveries 等表由
        // modules/Business/database/migrations 下的混合表迁移（create_transaction_tables）
        // 与历史迁移创建，无法按领域拆分，因此**保留在 Business 模块内**。
        //
        // 本目录是订单领域**新增**表结构的落脚点。
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
