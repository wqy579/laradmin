<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Stock\Seeders\PricePlanDemoSeeder;

/**
 * QA-08：给「价格方案」「客户特价」两页补初始配置。
 *
 * 为什么要写成迁移而不只改 Seeder：部署链路只跑 `artisan migrate --force`，全仓
 * 没有任何地方跑 db:seed（见 .github/workflows/deploy.yml:206）。只挂 DatabaseSeeder
 * 的话，已部署库执行完部署这两页仍然是 0 行——正是 QA-08 报的现象。
 *
 * 本迁移只负责「在存量库上补一次」：price_plans 只用 customer_levels（同属价格系统
 * 迁移自种，本迁移之前已存在），所以在此刻就能安全落方案；客户/商品维度的行留给
 * PricePlanDemoSeeder 在 db:seed 时补——迁移跑在 BusinessDataSeeder 之前，此时
 * CUST001/P001 还不存在。逻辑一份，两条入口共用，见该类的类注释。
 *
 * 幂等：PricePlanDemoSeeder 内部按唯一键「不存在才插」，重复 migrate 不产生第二行。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('price_plans')) {
            return;
        }
        (new PricePlanDemoSeeder)->run();
    }

    public function down(): void
    {
        if (! Schema::hasTable('price_plans')) {
            return;
        }
        $codes = array_column(PricePlanDemoSeeder::PLANS, 'code');
        $planIds = DB::table('price_plans')->whereIn('code', $codes)->pluck('id');
        if ($planIds->isNotEmpty() && Schema::hasTable('customer_special_price')) {
            // 只解绑不删行：删别人的特价数据不在本迁移的职权内
            DB::table('customer_special_price')->whereIn('price_plan_id', $planIds)
                ->update(['price_plan_id' => null, 'updated_at' => now()]);
        }
        DB::table('price_plans')->whereIn('code', $codes)->delete();
    }
};
