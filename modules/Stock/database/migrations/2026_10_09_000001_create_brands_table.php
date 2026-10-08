<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 品牌主数据 + 商品挂品牌 + 商品属性标记。
 *
 * 背景：连凯对标方案里「品牌」是报表的一级汇总维度（销售/库存/业务员报表都要按品牌筛选与分组），
 * 而 products 表此前根本没有品牌列——报表要按品牌出数，先得有品牌这个实体。
 *
 * 同时补两个商品属性标记（新品 / 重点），对应销售报表查询条件里的「商品属性」复选框组。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brands')) {
            Schema::create('brands', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50)->nullable()->unique()->comment('品牌编码');
                $table->string('name', 100)->comment('品牌名称');
                $table->integer('sort')->default(0)->comment('排序');
                $table->boolean('is_active')->default(true)->comment('启用');
                $table->timestamps();

                $table->index('name');
            });
        }

        if (! Schema::hasColumn('products', 'brand_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedBigInteger('brand_id')->nullable()->after('sub_category_id')->comment('品牌ID');
                $table->boolean('is_new')->default(false)->after('is_online')->comment('新品');
                $table->boolean('is_key')->default(false)->after('is_new')->comment('重点商品');

                $table->index('brand_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'brand_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn(['brand_id', 'is_new', 'is_key']);
            });
        }

        Schema::dropIfExists('brands');
    }
};
