<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F5 分销商城 —— 商城商品表（mall_products）。
 *
 * 单一数据源约束（不双写）：
 *   - 商品主数据（名称/编码/规格/图片/单位换算）一律读 products 表；
 *   - 商城价 = products.price_small / price_large / price_medium（后台「价格同步」
 *     只是把 products 的当前价刷新到商城页，不落独立价格列，杜绝两处价格漂移）；
 *   - 本表只存「上架/下架」开关与排序、展示备注等商城专属字段。
 *
 * product_id 唯一：一个后台商品最多对应一条商城上架记录。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mall_products')) {
            Schema::create('mall_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->comment('后台商品ID(products.id)');
                $table->boolean('is_on_shelf')->default(false)->comment('是否上架(前台可浏览/下单)');
                $table->integer('sort')->default(0)->comment('排序号(越小越靠前)');
                $table->string('slogan', 200)->nullable()->comment('商城宣传语');
                $table->text('remark')->nullable()->comment('展示备注');
                $table->foreignId('created_by')->nullable()->constrained('auth_user')->nullOnDelete()->comment('上架操作人');
                $table->timestamps();

                $table->unique('product_id');
                $table->index(['is_on_shelf', 'sort']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mall_products');
    }
};
