<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 会员管理（Member）模块业务单据表：储值充值 / 积分兑换 / 消费记录
 * 以及 sales_orders 的会员关联列。
 *
 * 审核流状态机：
 *   member_recharges  0待审核 → 1已审核 / 2已驳回 / 3已取消
 *     - 只有「已审核」才增加会员储值余额，并写现金流水
 *     - 驳回/取消不产生任何资金变动（此时尚未加钱，无需退回）
 *     - 已审核的单据禁止编辑/删除，冲正走红冲（后续扩展）
 *   point_orders      0待审核 → 1已审核待发货 → 2已发货 → 3已完成
 *                     任意未终态 → 4已驳回 / 5已取消
 *     - 只有「已审核」才扣减积分与兑换商品库存
 *     - 驳回/取消必须退回积分与库存（防重复扣减是核心风险点）
 *
 * 消费记录 member_consume_logs 是只追加的台账（append-only），
 * 记录每一笔储值/积分变动及变动后的余额快照，用于追溯对账。
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // 储值充值单
        // ------------------------------------------------------------------
        if (! Schema::hasTable('member_recharges')) {
            Schema::create('member_recharges', function (Blueprint $table) {
                $table->id();
                $table->string('recharge_no', 32)->unique()->comment('充值单号 CZ+Ymd+6位流水');
                $table->unsignedBigInteger('member_id')->comment('会员ID');
                $table->string('member_no', 32)->nullable()->comment('会员编号快照');
                $table->string('member_name', 100)->nullable()->comment('会员名称快照');
                $table->decimal('recharge_amount', 14, 2)->comment('充值金额（实际收到现金）');
                $table->decimal('gift_amount', 14, 2)->default(0)->comment('赠送金额');
                $table->decimal('total_amount', 14, 2)->comment('到账金额 = 充值 + 赠送');
                $table->string('pay_method', 20)->default('cash')->comment('支付方式：cash/wechat/alipay/bank/other');
                $table->date('recharge_date')->comment('充值日期');
                $table->unsignedBigInteger('operator_id')->nullable()->comment('经办人（auth_user.id）');
                $table->string('operator_name', 50)->nullable()->comment('经办人姓名快照');
                $table->tinyInteger('status')->default(0)->comment('状态：0待审核 1已审核 2已驳回 3已取消');
                $table->unsignedBigInteger('auditor_id')->nullable()->comment('审核人（auth_user.id）');
                $table->string('auditor_name', 50)->nullable()->comment('审核人姓名快照');
                $table->timestamp('audit_time')->nullable()->comment('审核/驳回时间');
                $table->string('reject_reason', 255)->nullable()->comment('驳回原因');
                $table->text('remark')->nullable()->comment('备注');
                $table->timestamps();

                $table->index('member_id');
                $table->index('status');
                $table->index('recharge_date');
            });
        }

        // ------------------------------------------------------------------
        // 积分兑换商品
        // ------------------------------------------------------------------
        if (! Schema::hasTable('point_products')) {
            Schema::create('point_products', function (Blueprint $table) {
                $table->id();
                $table->string('product_no', 32)->unique()->comment('兑换商品编号 JP+Ymd+6位流水');
                $table->string('name', 100)->comment('商品名称');
                $table->string('image', 255)->nullable()->comment('商品图片URL');
                $table->string('spec', 100)->nullable()->comment('规格');
                $table->integer('points')->default(0)->comment('所需积分');
                $table->integer('stock')->default(0)->comment('库存数量');
                $table->integer('exchanged')->default(0)->comment('已兑换数量');
                $table->integer('limit_per_person')->default(0)->comment('每人兑换上限，0=不限');
                $table->tinyInteger('status')->default(1)->comment('状态：1上架 0下架');
                $table->integer('sort')->default(0)->comment('排序');
                $table->text('remark')->nullable()->comment('备注');
                $table->timestamps();

                $table->index('status');
            });
        }

        // ------------------------------------------------------------------
        // 积分兑换订单
        // ------------------------------------------------------------------
        if (! Schema::hasTable('point_orders')) {
            Schema::create('point_orders', function (Blueprint $table) {
                $table->id();
                $table->string('order_no', 32)->unique()->comment('兑换单号 JF-yyyymm-####，按月连续不跳号（D7 编号正式化）');
                $table->unsignedBigInteger('member_id')->comment('会员ID');
                $table->string('member_no', 32)->nullable()->comment('会员编号快照');
                $table->string('member_name', 100)->nullable()->comment('会员名称快照');
                $table->unsignedBigInteger('product_id')->comment('兑换商品ID');
                $table->string('product_name', 100)->nullable()->comment('商品名称快照');
                $table->string('product_image', 255)->nullable()->comment('商品图片快照');
                $table->string('product_spec', 100)->nullable()->comment('规格快照');
                $table->integer('points_unit')->default(0)->comment('单个商品所需积分快照');
                $table->integer('quantity')->default(1)->comment('兑换数量');
                $table->integer('total_points')->comment('消耗积分 = points_unit × quantity');
                $table->string('contact_phone', 20)->nullable()->comment('收货联系电话');
                $table->string('shipping_address', 255)->nullable()->comment('收货地址');
                $table->tinyInteger('status')->default(0)->comment('状态：0待审核 1已审核待发货 2已发货 3已完成 4已驳回 5已取消');
                $table->timestamp('audit_time')->nullable()->comment('审核时间');
                $table->unsignedBigInteger('auditor_id')->nullable()->comment('审核人（auth_user.id）');
                $table->string('auditor_name', 50)->nullable()->comment('审核人姓名快照');
                $table->timestamp('ship_time')->nullable()->comment('发货时间');
                $table->string('ship_no', 100)->nullable()->comment('物流单号');
                $table->unsignedBigInteger('ship_by')->nullable()->comment('发货操作人（auth_user.id）');
                $table->timestamp('complete_time')->nullable()->comment('完成时间');
                $table->string('reject_reason', 255)->nullable()->comment('驳回/取消原因');
                $table->text('remark')->nullable()->comment('备注');
                $table->timestamps();

                $table->index('member_id');
                $table->index('product_id');
                $table->index('status');
            });
        }

        // ------------------------------------------------------------------
        // 会员消费记录（只追加台账）
        // ------------------------------------------------------------------
        if (! Schema::hasTable('member_consume_logs')) {
            Schema::create('member_consume_logs', function (Blueprint $table) {
                $table->id();
                $table->string('log_no', 32)->unique()->comment('记录编号 XH+Ymd+6位流水');
                $table->unsignedBigInteger('member_id')->comment('会员ID');
                $table->string('member_no', 32)->nullable()->comment('会员编号快照');
                $table->string('member_name', 100)->nullable()->comment('会员名称快照');
                $table->string('related_order_no', 32)->nullable()->comment('关联单号（订单号/充值单号/兑换单号）');
                $table->string('consume_type', 30)->comment('类型：order消费/recharge充值/points_deduct积分抵扣/order_refund订单退款/recharge_refund充值冲正');
                $table->string('direction', 10)->default('in')->comment('方向：in增加 out减少');
                $table->decimal('consume_amount', 14, 2)->default(0)->comment('涉及金额');
                $table->decimal('use_balance', 14, 2)->default(0)->comment('使用储值（消费时扣减的储值）');
                $table->integer('use_points')->default(0)->comment('使用积分');
                $table->integer('earn_points')->default(0)->comment('获得积分');
                $table->decimal('balance_after', 14, 2)->default(0)->comment('变动后储值余额快照');
                $table->integer('points_after')->default(0)->comment('变动后积分余额快照');
                $table->decimal('total_consume_after', 14, 2)->default(0)->comment('变动后累计消费快照');
                $table->unsignedBigInteger('operator_id')->nullable()->comment('操作人（auth_user.id）');
                $table->string('operator_name', 50)->nullable()->comment('操作人姓名快照');
                $table->text('remark')->nullable()->comment('备注');
                $table->timestamps();

                $table->index('member_id');
                $table->index('consume_type');
                $table->index('related_order_no');
            });
        }

        // ------------------------------------------------------------------
        // sales_orders 会员关联列
        //
        // member_id：会员ID 快照（下单时绑定，后续会员变动不影响历史订单）
        // use_balance：本单使用储值支付金额（元）
        // use_points：本单使用积分数量（换算金额由 point_rules.deduct_ratio 计算）
        // points_settled：会员结算幂等标记，防止 approve 多次调用重复加分
        //
        // 注意：sales_orders 挂在 customers 上（customer_id），会员经 customer 间接关联。
        // member_id 直接落在订单上是「快照冗余」，让报表与结算不必再 join members。
        // ------------------------------------------------------------------
        if (Schema::hasTable('sales_orders')) {
            if (! Schema::hasColumn('sales_orders', 'member_id')) {
                Schema::table('sales_orders', function (Blueprint $table) {
                    $table->unsignedBigInteger('member_id')->nullable()->after('customer_id')->comment('会员ID快照（members.id）');
                });
            }
            if (! Schema::hasColumn('sales_orders', 'use_balance')) {
                Schema::table('sales_orders', function (Blueprint $table) {
                    $table->decimal('use_balance', 14, 2)->default(0)->comment('本单使用储值支付金额');
                });
            }
            if (! Schema::hasColumn('sales_orders', 'use_points')) {
                Schema::table('sales_orders', function (Blueprint $table) {
                    $table->integer('use_points')->default(0)->comment('本单使用积分抵扣金额（元）');
                });
            }
            if (! Schema::hasColumn('sales_orders', 'points_settled')) {
                Schema::table('sales_orders', function (Blueprint $table) {
                    $table->boolean('points_settled')->default(false)->comment('会员积分/储值结算是否已完成（幂等标记）');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_orders')) {
            foreach (['member_id', 'use_balance', 'use_points', 'points_settled'] as $col) {
                if (Schema::hasColumn('sales_orders', $col)) {
                    Schema::table('sales_orders', function (Blueprint $table) use ($col) {
                        $table->dropColumn($col);
                    });
                }
            }
        }

        Schema::dropIfExists('member_consume_logs');
        Schema::dropIfExists('point_orders');
        Schema::dropIfExists('point_products');
        Schema::dropIfExists('member_recharges');
    }
};
