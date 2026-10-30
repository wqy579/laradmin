<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 固定资产模块 · 补齐高频筛选列索引（性能加固）
 *
 * 审计发现以下列被 where 条件实际过滤，但未建索引：
 *  - asset_card.department_id：资产台账按使用部门筛选（AssetPurchaseService::list 高频）
 *  - asset_purchase.department_id / user_id / status：购置单按部门/使用人/状态筛选
 *  - asset_depreciation.department_id：折旧凭证回写按部门分组（AssetDepreciationService::run）
 *
 * 另将 asset_sale.sale_no 提升为唯一索引：单号按 count()+1 生成且无唯一兜底，
 * 并发下可能静默重复入库（与 purchase_no/asset_no 的 unique 模式对齐，重复即回滚报错）。
 * 注意：asset_depreciation.batch_no 为同月多行共享的批次号，不能加 unique。
 *
 * 新增独立迁移（不修改已有建表迁移，保持 migrate:fresh 幂等）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_purchase', function (Blueprint $table) {
            $table->index('department_id');
            $table->index('user_id');
            $table->index('status');
        });
        Schema::table('asset_card', function (Blueprint $table) {
            $table->index('department_id');
        });
        Schema::table('asset_depreciation', function (Blueprint $table) {
            $table->index('department_id');
        });
        Schema::table('asset_sale', function (Blueprint $table) {
            // 普通 index → unique：并发重复单号由「静默入库」转为「抛异常回滚」。
            // 先移除冗余的普通索引，避免同列双索引浪费。
            $table->dropIndex(['sale_no']);
            $table->unique('sale_no');
        });
    }

    public function down(): void
    {
        Schema::table('asset_purchase', function (Blueprint $table) {
            $table->dropIndex(['department_id']);
            $table->dropIndex(['user_id']);
            $table->dropIndex(['status']);
        });
        Schema::table('asset_card', function (Blueprint $table) {
            $table->dropIndex(['department_id']);
        });
        Schema::table('asset_depreciation', function (Blueprint $table) {
            $table->dropIndex(['department_id']);
        });
        Schema::table('asset_sale', function (Blueprint $table) {
            $table->dropUnique(['sale_no']);
            $table->index('sale_no');
        });
    }
};
