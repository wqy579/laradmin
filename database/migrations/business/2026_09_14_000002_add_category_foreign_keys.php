<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 商品分类引用的数据完整性加固：
 * 1. 清理历史孤儿引用（此前删除分类未清理商品归属留下的脏数据），避免外键创建失败
 * 2. 为 products.main_category_id / sub_category_id 建立数据库级外键（nullOnDelete），
 *    与 ProductController@destroyCategory 的应用层清理互为兜底
 *
 * 注：SQLite 不支持对既有表追加外键（本地/测试环境跳过），外键仅在 MySQL（生产）创建。
 */
return new class extends Migration
{
    public function up(): void
    {
        $catIds = DB::table('product_categories')->pluck('id');

        DB::table('products')
            ->whereNotNull('main_category_id')
            ->whereNotIn('main_category_id', $catIds)
            ->update(['main_category_id' => null]);

        DB::table('products')
            ->whereNotNull('sub_category_id')
            ->whereNotIn('sub_category_id', $catIds)
            ->update(['sub_category_id' => null]);

        if (DB::getDriverName() === 'mysql') {
            Schema::table('products', function (Blueprint $table) {
                $table->foreign('main_category_id')->references('id')->on('product_categories')->nullOnDelete();
                $table->foreign('sub_category_id')->references('id')->on('product_categories')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('products', function (Blueprint $table) {
                $table->dropForeign(['main_category_id']);
                $table->dropForeign(['sub_category_id']);
            });
        }
    }
};
