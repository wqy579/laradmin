<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 促销管理模块表结构
 *
 * promotions        促销单主表
 * promotion_items   促销商品（折扣率/特价/买赠条件都落在这张表）
 * promotion_tiers   满减阶梯（满减促销专用）
 * promotion_orders  促销使用记录（每笔用了某促销的订单一行，供效果报表）
 *
 * 状态机：draft草稿 → upcoming未开始/active进行中 → ended已结束；手动 disabled已停用。
 * 「进行中/未开始/已结束」由当前时间相对 start_time/end_time 推导（列表实时算，
 *  不依赖定时任务回写，避免漏跑；字段 status 只记录 draft/disabled 等人工态）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('promotions')) {
            Schema::create('promotions', function (Blueprint $table) {
                $table->id();
                $table->string('promotion_no', 50)->unique()->comment('促销单号 CX+Ymd+6位');
                $table->string('name', 100)->comment('促销名称');
                $table->string('type', 20)->comment('类型: discount限时折扣/full_reduction满减/buy_gift买赠/special_price特价');
                $table->dateTime('start_time')->comment('开始时间');
                $table->dateTime('end_time')->comment('结束时间');
                $table->string('customer_scope', 20)->default('all')->comment('适用客户: all全部/level指定等级/specified指定客户');
                $table->json('customer_levels')->nullable()->comment('适用客户等级ID列表');
                $table->json('customer_ids')->nullable()->comment('适用客户ID列表');
                $table->integer('priority')->default(1)->comment('优先级1-100，越大越优先');
                $table->boolean('allow_stack')->default(false)->comment('是否允许与折扣/特价叠加');
                $table->string('status', 20)->default('draft')->comment('人工状态: draft草稿/disabled已停用（进行中/结束由时间推导）');
                $table->text('remark')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('creator_name', 50)->nullable();
                $table->timestamps();

                $table->index('type');
                $table->index('status');
                $table->index('start_time');
                $table->index('end_time');
            });
        }

        if (! Schema::hasTable('promotion_items')) {
            Schema::create('promotion_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('promotion_id')->comment('促销单ID');
                $table->unsignedBigInteger('product_id')->comment('商品ID');
                $table->string('product_code', 50)->nullable();
                $table->string('product_name', 200);
                $table->string('spec', 100)->nullable();
                $table->string('unit', 20)->nullable();
                $table->decimal('original_price', 10, 2)->default(0)->comment('原价（小单位）');
                $table->decimal('discount_rate', 5, 2)->nullable()->comment('折扣率（限时折扣，如8.5=8.5折）');
                $table->decimal('special_price', 10, 2)->nullable()->comment('特价（特价促销）');
                $table->unsignedBigInteger('gift_product_id')->nullable()->comment('赠品商品ID（买赠）');
                $table->integer('gift_qty')->nullable()->comment('赠送数量（买赠）');
                $table->integer('buy_qty')->nullable()->comment('购买数量条件（买赠）');
                $table->timestamps();

                $table->index('promotion_id');
                $table->index('product_id');
            });
        }

        if (! Schema::hasTable('promotion_tiers')) {
            Schema::create('promotion_tiers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('promotion_id');
                $table->decimal('threshold_amount', 12, 2)->comment('满额门槛');
                $table->decimal('discount_amount', 12, 2)->comment('减免金额');
                $table->integer('sort')->default(0);
                $table->timestamps();

                $table->index('promotion_id');
            });
        }

        if (! Schema::hasTable('promotion_orders')) {
            Schema::create('promotion_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('promotion_id');
                $table->unsignedBigInteger('order_id');
                $table->string('order_no', 50);
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->decimal('discount_amount', 12, 2)->default(0)->comment('本单优惠金额');
                $table->timestamps();

                $table->index('promotion_id');
                $table->index('order_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_orders');
        Schema::dropIfExists('promotion_tiers');
        Schema::dropIfExists('promotion_items');
        Schema::dropIfExists('promotions');
    }
};
