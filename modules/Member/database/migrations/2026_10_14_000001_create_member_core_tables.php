<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 会员管理（Member）模块核心表：会员档案 / 会员等级 / 积分规则
 *
 * 与现有体系的关系（重要，避免语义冲突）：
 *   - members.customer_id → customers.id（1:0..1，唯一索引）：会员绑定客户档案。
 *     销售订单都挂在客户上，会员身份经客户间接关联，因此 orders 侧只需加 member_id 快照。
 *   - ⚠️ 本模块的 member_levels（会员等级）与 Stock 模块已有的 customer_levels（客户等级价）
 *     是两套独立概念，不要合并：
 *       customer_levels = 批发业务分级，驱动 PriceService 取价（促销价 > 等级单独价 > 等级默认折扣 > 标准售价）
 *       member_levels   = 零售会员权益，驱动积分/储值/累计消费升级
 *     用户决策：member_levels.discount_rate 第一期只作展示，不接入 PriceService 取价链。
 *   - customers.balance 是「欠款余额」（正=欠款，负=预付款），与会员储值无关；
 *     会员储值一律走 members.balance + member_recharges。
 *
 * 金额统一 decimal(14,2)（对齐 purchase_applications），积分用整型，折扣率用百分比
 * （100.00=不打折，95.00=95折，乘数=discount_rate/100）。
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // 会员等级
        // ------------------------------------------------------------------
        if (! Schema::hasTable('member_levels')) {
            Schema::create('member_levels', function (Blueprint $table) {
                $table->id();
                $table->string('level_no', 32)->unique()->comment('等级编号，如 LV0/LV1/LV2/LV3');
                $table->string('name', 50)->comment('等级名称：普通会员/银卡会员/金卡会员/钻石卡');
                $table->decimal('upgrade_amount', 14, 2)->default(0)->comment('升级门槛：累计消费达到X元');
                $table->decimal('discount_rate', 5, 2)->default(100)->comment('折扣率%：100=不打折，95=95折');
                $table->string('color', 20)->default('#909399')->comment('等级颜色，用于前端标签');
                $table->text('description')->nullable()->comment('等级说明/权益描述');
                $table->integer('sort')->default(0)->comment('排序，升序');
                $table->tinyInteger('status')->default(1)->comment('状态：1启用 0禁用');
                $table->boolean('is_system')->default(false)->comment('系统内置等级，禁止删除');
                $table->timestamps();

                $table->index('status');
            });
        }

        // 初始化 4 档会员等级（幂等：按 level_no 判重）
        $defaultLevels = [
            ['level_no' => 'LV0', 'name' => '普通会员', 'upgrade_amount' => 0, 'discount_rate' => 100, 'color' => '#909399', 'description' => '注册即成为普通会员，享受积分累积', 'sort' => 1],
            ['level_no' => 'LV1', 'name' => '银卡会员', 'upgrade_amount' => 5000, 'discount_rate' => 95, 'color' => '#409EFF', 'description' => '累计消费满 5,000 元升级', 'sort' => 2],
            ['level_no' => 'LV2', 'name' => '金卡会员', 'upgrade_amount' => 20000, 'discount_rate' => 90, 'color' => '#E6A23C', 'description' => '累计消费满 20,000 元升级', 'sort' => 3],
            ['level_no' => 'LV3', 'name' => '钻石卡', 'upgrade_amount' => 50000, 'discount_rate' => 85, 'color' => '#8E44AD', 'description' => '累计消费满 50,000 元升级', 'sort' => 4],
        ];

        foreach ($defaultLevels as $level) {
            $exists = DB::table('member_levels')->where('level_no', $level['level_no'])->exists();
            if (! $exists) {
                DB::table('member_levels')->insert($level + [
                    'status' => 1,
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // ------------------------------------------------------------------
        // 会员档案
        // ------------------------------------------------------------------
        if (! Schema::hasTable('members')) {
            Schema::create('members', function (Blueprint $table) {
                $table->id();
                $table->string('member_no', 32)->unique()->comment('会员编号 HY+Ymd+6位流水，自动生成');
                $table->unsignedBigInteger('customer_id')->nullable()->comment('绑定客户ID（customers.id），1:0..1');
                $table->string('customer_name', 200)->nullable()->comment('客户名称快照，便于列表展示');
                $table->string('name', 100)->comment('会员名称');
                $table->string('contact', 50)->nullable()->comment('联系人');
                $table->string('phone', 20)->nullable()->comment('联系电话');
                $table->tinyInteger('gender')->default(0)->comment('性别：0未知 1男 2女');
                $table->date('birthday')->nullable()->comment('出生日期');
                $table->string('email', 100)->nullable()->comment('电子邮箱');
                $table->string('province', 50)->nullable()->comment('省');
                $table->string('city', 50)->nullable()->comment('市');
                $table->string('district', 50)->nullable()->comment('区');
                $table->string('address', 255)->nullable()->comment('详细地址');
                $table->unsignedBigInteger('level_id')->default(1)->comment('会员等级ID（member_levels.id）');
                $table->integer('points')->default(0)->comment('积分余额');
                $table->decimal('balance', 14, 2)->default(0)->comment('储值余额');
                $table->decimal('total_consume', 14, 2)->default(0)->comment('累计消费金额');
                $table->integer('total_orders')->default(0)->comment('累计订单数');
                $table->date('register_date')->nullable()->comment('注册日期');
                $table->tinyInteger('status')->default(1)->comment('状态：1正常 0冻结');
                $table->text('remark')->nullable()->comment('备注');
                $table->timestamps();

                $table->index('customer_id');
                $table->index('phone');
                $table->index('level_id');
                $table->index('status');
            });
        }

        // ------------------------------------------------------------------
        // 积分规则（key-value，JSON 值）
        // ------------------------------------------------------------------
        if (! Schema::hasTable('point_rules')) {
            Schema::create('point_rules', function (Blueprint $table) {
                $table->id();
                $table->string('rule_key', 50)->unique()->comment('规则键名，程序读写的稳定标识');
                $table->string('rule_name', 100)->comment('规则名称，前端展示');
                $table->text('rule_value')->comment('规则值，JSON 格式字符串');
                $table->text('description')->nullable()->comment('规则说明');
                $table->timestamps();
            });
        }

        $defaultRules = [
            ['rule_key' => 'consume_ratio', 'rule_name' => '消费积分比例', 'rule_value' => '{"amount":1,"points":1}', 'description' => '每消费 amount 元获得 points 积分'],
            ['rule_key' => 'category_ratio', 'rule_name' => '分类积分比例', 'rule_value' => '[]', 'description' => '特定商品分类的积分比例覆盖，空数组表示不启用'],
            ['rule_key' => 'register_gift', 'rule_name' => '注册赠送积分', 'rule_value' => '{"points":0}', 'description' => '新会员注册即送积分'],
            ['rule_key' => 'birthday_gift', 'rule_name' => '生日赠送积分', 'rule_value' => '{"points":0}', 'description' => '会员生日当月赠送积分'],
            ['rule_key' => 'invite_gift', 'rule_name' => '邀请赠送积分', 'rule_value' => '{"inviter":0,"invitee":0}', 'description' => '邀请人/被邀请人各获积分'],
            ['rule_key' => 'deduct_ratio', 'rule_name' => '积分抵扣比例', 'rule_value' => '{"points":100,"amount":1}', 'description' => 'points 积分抵扣 amount 元'],
            ['rule_key' => 'deduct_limit', 'rule_name' => '积分抵扣上限', 'rule_value' => '{"type":"unlimited","value":0}', 'description' => '单笔订单积分抵扣上限，type: unlimited/percent/fixed'],
            ['rule_key' => 'deduct_threshold', 'rule_name' => '积分使用门槛', 'rule_value' => '{"amount":0}', 'description' => '订单实付满 amount 元才可使用积分'],
            ['rule_key' => 'expire_type', 'rule_name' => '积分有效期', 'rule_value' => '{"type":"forever","value":""}', 'description' => '积分有效期类型：forever/yearly'],
            ['rule_key' => 'expire_remind', 'rule_name' => '过期提醒', 'rule_value' => '{"days":[]}', 'description' => '过期前提醒天数'],
        ];

        foreach ($defaultRules as $rule) {
            $exists = DB::table('point_rules')->where('rule_key', $rule['rule_key'])->exists();
            if (! $exists) {
                DB::table('point_rules')->insert($rule + [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
        Schema::dropIfExists('point_rules');
        Schema::dropIfExists('member_levels');
    }
};
