<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 清理遗留列（仅 MySQL 生产库需要增量删除）：
 * - products.category_id：旧版分类设计的单列，业务已全面使用 main_category_id / sub_category_id；
 *   建表迁移已同步移除，全新环境不再产生该列
 * - product_categories.product_count：冗余计数列，真实计数已改为接口内实时聚合（ProductController@categories）
 *
 * 注：SQLite 不走本迁移（本地/测试库用 migrate:fresh 重建即可获得干净结构；
 * 且 SQLite dropColumn 的表重建会被残留外键定义卡住）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasColumn('products', 'category_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropForeign(['category_id']);
                $table->dropColumn('category_id');
            });
        }

        if (Schema::hasColumn('product_categories', 'product_count')) {
            Schema::table('product_categories', function (Blueprint $table) {
                $table->dropColumn('product_count');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (!Schema::hasColumn('product_categories', 'product_count')) {
            Schema::table('product_categories', function (Blueprint $table) {
                $table->integer('product_count')->default(0)->comment('商品数量');
            });
        }

        if (!Schema::hasColumn('products', 'category_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            });
        }
    }
};
