<?php

namespace Modules\Stock\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QA-08：价格方案 / 客户特价两页「查询返回 0 行」的数据初始化。
 *
 * 为什么两处调用（DatabaseSeeder + 数据迁移 2026_11_06_000002）：
 *   - 部署链路只跑 `artisan migrate --force`，不跑 db:seed（见 deploy.yml），
 *     所以只挂 Seeder 的话线上这两页永远是空的，必须由迁移在存量库上补。
 *   - 反过来，迁移在 `migrate:fresh --seed` 里跑在 BusinessDataSeeder 之前，
 *     那时 CUST001/P001 还不存在，客户级数据必须靠 Seeder 这条路径补上。
 * 两条路径都指向本类，逻辑只有一份；本类幂等，重复执行不产生第二行。
 *
 * 幂等键：price_plans.code 唯一；product_level_prices (product_id, level_id) 唯一；
 * customer_special_price (customer_id, product_id) 唯一。已存在的行一律不覆盖——
 * 用户手工调过的价格不能被重新播种冲掉。
 */
class PricePlanDemoSeeder extends Seeder
{
    /**
     * 方案定义。level 用 customer_levels.code 解析而非写死自增 ID——等级表在不同
     * 库里的 ID 位置可能不同（迁移自种 4 档，但存量库可能已被增删）。
     */
    public const PLANS = [
        ['code' => 'PP-LV1-SPECIAL', 'name' => '一级批发商专项特价', 'type' => 'special_price', 'level' => 'LV1', 'discount' => null, 'remark' => '一级批发商渠道特价，绑定等级便于批量落价'],
        ['code' => 'PP-LV1-DISCOUNT', 'name' => '一级批发商等级价', 'type' => 'level_price', 'level' => 'LV1', 'discount' => 9.5, 'remark' => '等级 9.5 折；未设单品等级价时按折扣取价'],
        ['code' => 'PP-VIP-DISCOUNT', 'name' => 'VIP 客户等级价', 'type' => 'level_price', 'level' => 'VIP', 'discount' => 8.0, 'remark' => 'VIP 8 折'],
        ['code' => 'PP-RETAIL-STANDARD', 'name' => '零售价（默认价）', 'type' => 'default_price', 'level' => null, 'discount' => 10.0, 'remark' => '无特价无等级价时按商品标准售价成交'],
    ];

    public function run(): void
    {
        $planIds = $this->seedPlans();
        $this->seedCustomerPricing($planIds);
    }

    /** @return array<string,int> code => price_plans.id */
    private function seedPlans(): array
    {
        if (! Schema::hasTable('price_plans')) {
            return [];
        }
        // 生效窗口相对执行日计算，避免演示数据放久了整体过期
        $start = now()->subMonth()->toDateString();
        $end = now()->addMonths(11)->toDateString();
        $ids = [];

        foreach (self::PLANS as $plan) {
            $existing = DB::table('price_plans')->where('code', $plan['code'])->first(['id']);
            if ($existing) {
                $ids[$plan['code']] = $existing->id;

                continue;
            }
            // 只读 customer_levels（价格系统迁移自种，恒存在），不依赖业务主数据，
            // 因此本方法可安全地在 BusinessDataSeeder 之前运行。
            $levelId = $plan['level']
                ? DB::table('customer_levels')->where('code', $plan['level'])->value('id')
                : null;

            $ids[$plan['code']] = DB::table('price_plans')->insertGetId([
                'name' => $plan['name'],
                'code' => $plan['code'],
                'type' => $plan['type'],
                'discount' => $plan['discount'],
                'level_id' => $levelId,
                'start_date' => $start,
                'end_date' => $end,
                'status' => true,
                'remark' => $plan['remark'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    /**
     * 客户级取价数据。刻意只挂 BusinessDataSeeder 预置的演示主体
     * （客户A/客户B × P001/P002/P003/P004）：这三张表会真实改变
     * PriceService::resolve 的成交价，绝不能给未经种子创建的正式客户凭空造价。
     * 主体不齐（未跑默认种子 / 已被删）就整段跳过。
     *
     * 落出的四档样本，让「价格解析」页与订单弹窗都能看出单价随客户变化：
     *   客户A(LV1) × P001 → 等级单独价 1.85   （压过 9.5 折默认折扣 1.90）
     *   客户A(LV1) × P003 → 客户特价 6.80     （压过等级价）
     *   客户B(VIP) × P002 → 等级单独价 2.80
     *   客户B(VIP) × P004 → 客户特价 19.90
     *   其余组合        → 商品标准售价（默认价）
     */
    private function seedCustomerPricing(array $planIds): void
    {
        if (! Schema::hasTable('customers') || ! Schema::hasTable('products')) {
            return;
        }
        $custA = DB::table('customers')->where('code', 'CUST001')->value('id');
        $custB = DB::table('customers')->where('code', 'CUST002')->value('id');
        if (! $custA || ! $custB) {
            return;
        }
        $pid = DB::table('products')->whereIn('code', ['P001', 'P002', 'P003', 'P004'])->pluck('id', 'code')->all();
        if (count($pid) < 4) {
            return;
        }
        $lv1 = DB::table('customer_levels')->where('code', 'LV1')->value('id');
        $vip = DB::table('customer_levels')->where('code', 'VIP')->value('id');
        if (! $lv1 || ! $vip) {
            return;
        }

        // 1) 演示客户挂等级。仅补空值，已有真实等级分配不动。
        foreach ([[$custA, $lv1], [$custB, $vip]] as [$custId, $levelId]) {
            if (DB::table('customers')->where('id', $custId)->whereNull('level_id')->exists()) {
                DB::table('customers')->where('id', $custId)->update(['level_id' => $levelId, 'updated_at' => now()]);
            }
        }

        // 2) 商品 × 等级 单独售价
        if (Schema::hasTable('product_level_prices')) {
            foreach ([['P001', $lv1, 1.85], ['P002', $vip, 2.80]] as [$code, $levelId, $price]) {
                $exists = DB::table('product_level_prices')
                    ->where('product_id', $pid[$code])->where('level_id', $levelId)->exists();
                if ($exists) {
                    continue;
                }
                DB::table('product_level_prices')->insert([
                    'product_id' => $pid[$code],
                    'level_id' => $levelId,
                    'price' => $price,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 3) 客户特价。表内已有任何配置就整体不掺演示行——真实环境里这页通常已被
        //    人工维护，塞两行「演示」字样的特价只会污染成交口径。
        if (! Schema::hasTable('customer_special_price') || DB::table('customer_special_price')->exists()) {
            return;
        }
        $start = now()->subMonth()->toDateString();
        $end = now()->addMonths(11)->toDateString();
        $specials = [
            [$custA, $pid['P003'], 6.80, $planIds['PP-LV1-SPECIAL'] ?? null, '演示：饼干渠道特价，压过等级价'],
            [$custB, $pid['P004'], 19.90, null, '演示：洗衣液大客户特价'],
        ];
        foreach ($specials as [$customerId, $productId, $price, $planId, $remark]) {
            if (DB::table('customer_special_price')
                ->where('customer_id', $customerId)->where('product_id', $productId)->exists()) {
                continue;
            }
            DB::table('customer_special_price')->insert([
                'price_plan_id' => $planId,
                'customer_id' => $customerId,
                'product_id' => $productId,
                'price' => $price,
                'start_date' => $start,
                'end_date' => $end,
                'status' => true,
                'remark' => $remark,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
