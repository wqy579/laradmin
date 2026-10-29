<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 固定资产 · 折旧记录表（D4）
 *
 * 一条记录 = 一张卡片 × 一个期间（month）的计提结果。
 * 同一期间只允许一个批次（batch_no 前缀 ZJ-yyyymm），期间锁定由
 * AssetDepreciationService 在事务内用「该月是否已存在记录」收口：
 * 已折旧期间不可重复计提。
 *
 * 累计已提/账面净值 均为「计提后」的值（累计折旧累加、净值扣减后），
 * 与 asset_card 上的实时值口径一致，便于对账。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_depreciation')) {
            return;
        }

        Schema::create('asset_depreciation', function (Blueprint $table) {
            $table->id();
            $table->string('batch_no', 32)->comment('折旧批次号 ZJ-yyyymm-####');
            $table->string('month', 7)->comment('折旧期间 YYYY-MM');
            $table->unsignedBigInteger('asset_card_id')->comment('资产卡片');
            $table->string('asset_no', 32)->comment('资产编号（快照）');
            $table->string('asset_name', 100)->comment('资产名称（快照）');
            $table->decimal('original_value', 14, 2)->comment('原值');
            $table->decimal('residual_rate', 5, 2)->comment('残值率 %');
            $table->decimal('month_amount', 14, 2)->comment('本月折旧额');
            $table->decimal('accumulated_dep', 14, 2)->comment('计提后累计折旧');
            $table->decimal('net_value', 14, 2)->comment('计提后账面净值');
            $table->unsignedBigInteger('department_id')->nullable()->comment('使用部门');
            $table->string('department_name', 60)->nullable()->comment('使用部门名（按部门分摊凭证用）');
            $table->unsignedBigInteger('voucher_id')->nullable()->comment('折旧凭证ID，null=未登记');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('batch_no');
            $table->index(['month', 'asset_card_id']);
            $table->index('asset_card_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_depreciation');
    }
};
