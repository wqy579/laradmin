<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Auth\Seeders\AuthSeeder;
use Modules\Business\Seeders\BusinessDataSeeder;
use Modules\Business\Seeders\BusinessSeeder;
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
|   BusinessDataSeeder —— 商品 / 分类 / 仓库等主数据，依赖上面全部
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
            BusinessDataSeeder::class,
        ]);
    }
}
