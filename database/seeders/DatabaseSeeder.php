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
