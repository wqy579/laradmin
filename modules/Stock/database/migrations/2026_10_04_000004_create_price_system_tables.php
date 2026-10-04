<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 价格体系：
 *  - customer_levels        客户等级（默认4档，不可删）
 *  - product_level_prices   商品在各等级下的单独价格
 *  - product_price_history  每次改价留痕
 *  - customers.level_id      客户挂等级（NULL=普通客户，按标准售价）
 *
 * 取价优先级：促销价 > 等级单独价 > 等级默认折扣价 > 标准售价。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_levels')) {
            Schema::create('customer_levels', function (Blueprint $table) {
                $table->id();
                $table->string('name', 50)->comment('等级名称');
                $table->string('code', 20)->unique()->comment('等级编码 LV1/VIP');
                $table->decimal('default_discount', 4, 1)->default(9.0)->comment('默认折扣率，9.0=9折');
                $table->integer('sort')->default(0);
                $table->boolean('is_system')->default(false)->comment('系统默认等级不可删');
                $table->boolean('status')->default(true)->comment('启用');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('product_level_prices')) {
            Schema::create('product_level_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('level_id')->constrained('customer_levels')->cascadeOnDelete();
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
                $table->unique(['product_id', 'level_id']);
            });
        }

        if (! Schema::hasTable('product_price_history')) {
            Schema::create('product_price_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('price_type', 30)->comment('standard 或 level_{id}');
                $table->string('price_type_name', 50)->nullable();
                $table->decimal('old_price', 10, 2)->nullable();
                $table->decimal('new_price', 10, 2)->nullable();
                $table->string('change_type', 20)->default('manual')->comment('manual/batch');
                $table->string('batch_rule', 100)->nullable();
                $table->unsignedBigInteger('operator_id')->nullable();
                $table->string('operator_name', 50)->nullable();
                $table->timestamps();
                $table->index(['product_id', 'price_type']);
            });
        }

        if (! Schema::hasColumn('customers', 'level_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->foreignId('level_id')->nullable()->after('level')
                    ->constrained('customer_levels')->nullOnDelete();
            });
        }

        // 默认4档等级（幂等）
        $defaults = [
            ['name' => '一级批发商', 'code' => 'LV1', 'default_discount' => 9.5, 'sort' => 1],
            ['name' => '二级批发商', 'code' => 'LV2', 'default_discount' => 9.0, 'sort' => 2],
            ['name' => '零售店', 'code' => 'LV3', 'default_discount' => 8.5, 'sort' => 3],
            ['name' => 'VIP客户', 'code' => 'VIP', 'default_discount' => 8.0, 'sort' => 4],
        ];
        foreach ($defaults as $d) {
            if (! DB::table('customer_levels')->where('code', $d['code'])->exists()) {
                DB::table('customer_levels')->insert(array_merge($d, [
                    'is_system' => true, 'status' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]));
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'level_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('level_id');
            });
        }
        Schema::dropIfExists('product_price_history');
        Schema::dropIfExists('product_level_prices');
        Schema::dropIfExists('customer_levels');
    }
};
