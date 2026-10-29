<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F7 价格方案 + 客户特价。
 *
 *  - price_plans              价格方案：命名一套定价策略（特价/等级价/默认价三档之一），
 *                             可绑定客户等级、折扣与生效期间，是客户特价的政策头。
 *  - customer_special_price   客户特价：客户 × 商品 的特价落账，可挂到价格方案下。
 *
 * 取价优先级（与 E4 报表口径一致）：客户特价 > 促销价 > 等级价 > 默认价（price_small）。
 * 本迁移只建表与列，不写任何业务数据。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('price_plans')) {
            Schema::create('price_plans', function (Blueprint $table) {
                $table->id();
                $table->string('name', 50)->comment('方案名称');
                $table->string('code', 30)->unique()->comment('方案编码');
                $table->string('type', 20)->default('special_price')
                    ->comment('定价档位：special_price 特价 / level_price 等级价 / default_price 默认价');
                $table->decimal('discount', 4, 1)->nullable()->comment('折扣率，8.5=8.5折，等级价方案使用');
                $table->foreignId('level_id')->nullable()->constrained('customer_levels')->nullOnDelete()
                    ->comment('适用客户等级');
                $table->date('start_date')->nullable()->comment('生效开始');
                $table->date('end_date')->nullable()->comment('生效结束');
                $table->boolean('status')->default(true)->comment('启用');
                $table->string('remark', 255)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('customer_special_price')) {
            Schema::create('customer_special_price', function (Blueprint $table) {
                $table->id();
                $table->foreignId('price_plan_id')->nullable()->constrained('price_plans')->nullOnDelete()
                    ->comment('来源价格方案');
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->decimal('price', 10, 2)->comment('特价');
                $table->date('start_date')->nullable()->comment('特价生效开始');
                $table->date('end_date')->nullable()->comment('特价生效结束');
                $table->boolean('status')->default(true)->comment('启用');
                $table->string('remark', 255)->nullable();
                $table->timestamps();
                $table->unique(['customer_id', 'product_id'], 'csp_customer_product_unique');
                $table->index(['customer_id', 'status'], 'csp_customer_status_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_special_price');
        Schema::dropIfExists('price_plans');
    }
};
