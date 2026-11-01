<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Agreement\Seeders\AgreementSeeder;
use Modules\Archive\Seeders\ArchiveMenuSeeder;
use Modules\Asset\Seeders\AssetSeeder;
use Modules\Auth\Seeders\AuthSeeder;
use Modules\Business\Seeders\BusinessDataSeeder;
use Modules\Business\Seeders\BusinessSeeder;
use Modules\Cash\Seeders\CashSeeder;
use Modules\Hr\Seeders\HrSeeder;
use Modules\Ledger\Seeders\LedgerSeeder;
use Modules\Mall\Seeders\MallMenuSeeder;
use Modules\Order\Seeders\OrderMenuSeeder;
use Modules\Performance\Seeders\PerformanceMenuSeeder;
use Modules\Stock\Seeders\PricePlanDemoSeeder;
use Modules\Stock\Seeders\StockMenuSeeder;
use Modules\System\Seeders\SystemSeeder;

/*
|--------------------------------------------------------------------------
| 种子数据的中央编排
|--------------------------------------------------------------------------
|
| 各模块的种子数据落在各自模块内（modules/<Module>/Seeders/），本类只做编排：
| 决定顺序，不决定内容。Laravel 没有 loadSeedersFrom，编排必须显式写在这里。
|
| 顺序是硬约束，不可随意调整：
|   SystemSeeder       —— 配置、字典（其余模块的种子都不依赖它，放最前只为稳定）
|   AuthSeeder         —— 用户 / 角色 / 权限
|   BusinessSeeder     —— 只写权限菜单，依赖 AuthSeeder 已建好 permissions 表数据
|   OrderMenuSeeder / StockMenuSeeder / MallMenuSeeder / ArchiveMenuSeeder
|                      —— 把原先只在迁移里注册的菜单（订货中心、库存/价格、
|                        分销增值、档案中心）在 AuthSeeder truncate 之后重建，
|                        否则 db:seed 会把这些菜单连同按钮权限一起抹掉。
|                        四者的子菜单挂在 order/inventory/price/data 分组下，
|                        这些分组由 BusinessSeeder 建，故必须排它之后。
|   LedgerSeeder       —— 总账菜单/权限节点/预置科目，依赖 AuthSeeder 已建好 permissions
|   BusinessDataSeeder —— 商品 / 分类 / 仓库等主数据，依赖上面全部
|   PricePlanDemoSeeder —— 价格方案/客户特价（QA-08），依赖 BusinessDataSeeder 的
|                        客户与商品；同一条播种逻辑也被数据迁移
|                        2026_11_06_000002 调用（部署只跑 migrate --force，不跑 seed），
|                        那里跑不到本条依赖，故客户级数据只在 seed 这条路径上补齐。
|
| FlowDemoSeeder（端到端业务流程演示数据：销售→配送→车销→借还→换货→库存→收付款→退货）
| 不在默认链路里——MigrationSmokeTest 基线要求默认种子不产生任何业务单据和库存行。
| 需要 QA 演示数据时显式执行：
|   php artisan db:seed --class="Modules\\Business\\Seeders\\FlowDemoSeeder"
| 它依赖 BusinessDataSeeder 的主数据（用户/客户/仓库/商品/供应商/车辆），须先跑默认种子。
*/
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SystemSeeder::class,
            AuthSeeder::class,
            BusinessSeeder::class,
            // 迁移期注册菜单的补种：必须紧跟 BusinessSeeder（order/inventory/
            // price/data 分组在那儿建），且在 AuthSeeder 之后（它 truncate）。
            OrderMenuSeeder::class,
            StockMenuSeeder::class,
            MallMenuSeeder::class,
            ArchiveMenuSeeder::class,
            LedgerSeeder::class,
            BusinessDataSeeder::class,
            // 依赖 BusinessDataSeeder 的客户/商品，必须排其后
            PricePlanDemoSeeder::class,
            PerformanceMenuSeeder::class,
            CashSeeder::class,
            HrSeeder::class,
            AgreementSeeder::class,
            AssetSeeder::class,
        ]);
    }
}
