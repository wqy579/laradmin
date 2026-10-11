<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 补全品牌主数据的可运营字段。
 *
 * 2026_10_09 的建表迁移只落了 id/code/name/sort/is_active/timestamps，
 * 缺三块报表与运营需要的列：
 *   - category_id：品牌关联一级分类。连凯的「品牌=分类一级维度」在 schema 层
 *     原来没有实体映射，dashboard 的 categoryProportion 只能靠 join 反推。
 *   - remark：品牌备注。
 *   - created_by / updated_by：项目内已有的审计惯例（Transfer/StockAdjust/Stocktaking）。
 *   - deleted_at：支持软删。destroy() 允许删掉无关联商品的品牌，但不物理删除，
 *     避免历史订单里的 brand_id 变成孤儿外键。
 *
 * 幂等：建表迁移只在表不存在时执行，本迁移用 hasColumn 判重，可重复运行。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (! Schema::hasColumn('brands', 'category_id')) {
                $table->unsignedBigInteger('category_id')->nullable()->after('name')->comment('关联一级分类ID');
                $table->index('category_id');
            }
            if (! Schema::hasColumn('brands', 'remark')) {
                $table->string('remark', 500)->nullable()->after('is_active')->comment('备注');
            }
            if (! Schema::hasColumn('brands', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('remark')->comment('创建人ID');
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by')->comment('更新人ID');
            }
            if (! Schema::hasColumn('brands', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['category_id', 'remark', 'created_by', 'updated_by']);
        });
    }
};
