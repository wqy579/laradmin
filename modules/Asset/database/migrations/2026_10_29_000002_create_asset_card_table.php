<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 固定资产 · 资产卡片表（D3）
 *
 * 一张卡片 = 一台资产：购置单数量 N 生成 N 张卡片，单台原值 = 单价。
 * D4（月折旧/变卖）在本表上扩展，故此处只留累计折旧与净值的写入位，
 * 由 AssetLedgerService 汇总，D3 阶段净值恒等于原值、累计折旧恒为 0。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_card')) {
            return;
        }

        Schema::create('asset_card', function (Blueprint $table) {
            $table->id();
            $table->string('asset_no', 32)->comment('资产编号 ZC-yyyymm-####');
            $table->unsignedBigInteger('purchase_id')->nullable()->comment('来源购置单');
            $table->string('asset_name', 100)->comment('资产名称');
            $table->string('spec', 100)->nullable()->comment('规格型号');
            $table->decimal('original_value', 14, 2)->default(0)->comment('单台原值');
            $table->unsignedInteger('use_months')->default(60)->comment('预计使用月份');
            $table->decimal('residual_rate', 5, 2)->default(5)->comment('残值率 %');
            $table->string('dep_method', 20)->default('straight')->comment('折旧方式');
            $table->decimal('accumulated_dep', 14, 2)->default(0)->comment('累计折旧（D4 计提）');
            $table->decimal('net_value', 14, 2)->default(0)->comment('账面净值 = 原值 − 累计折旧');
            $table->unsignedBigInteger('department_id')->nullable()->comment('使用部门');
            $table->string('department_name', 60)->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->comment('使用人');
            $table->string('user_name', 60)->nullable()->comment('使用人姓名');
            $table->string('status', 20)->default('inuse')->comment('inuse/idle/disposed（已处置由 D4 变卖写入）');
            $table->date('acquire_date')->comment('取得日期');
            $table->timestamps();

            $table->unique('asset_no');
            $table->index('purchase_id');
            $table->index('status');
            $table->index('acquire_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_card');
    }
};
