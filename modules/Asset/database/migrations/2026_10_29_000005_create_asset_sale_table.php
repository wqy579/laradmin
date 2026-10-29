<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 固定资产 · 资产变卖单表（D4）
 *
 * 一条记录 = 一张卡片的变卖。变卖时取卡片当前净值（账面净值 = 原值 − 累计折旧），
 * 损益 = 变卖价 − 净值（正=营业外收入，负=营业外支出），并同步将卡片状态置为
 * 已售（sold）。voucher_id 非空即代表已登记处置凭证（复用 D3 凭证联动范式）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_sale')) {
            return;
        }

        Schema::create('asset_sale', function (Blueprint $table) {
            $table->id();
            $table->string('sale_no', 32)->comment('变卖单号 BM-yyyymm-####');
            $table->unsignedBigInteger('asset_card_id')->comment('资产卡片');
            $table->string('asset_no', 32)->comment('资产编号（快照）');
            $table->string('asset_name', 100)->comment('资产名称（快照）');
            $table->date('sale_date')->comment('变卖日期');
            $table->decimal('sale_amount', 14, 2)->comment('变卖价');
            $table->decimal('net_value', 14, 2)->comment('变卖时净值');
            $table->decimal('accumulated_dep', 14, 2)->default(0)->comment('变卖时累计折旧');
            $table->decimal('original_value', 14, 2)->default(0)->comment('变卖时原值');
            $table->decimal('gain_loss', 14, 2)->comment('变卖损益 = 变卖价 − 净值');
            $table->decimal('closing_fee', 14, 2)->default(0)->comment('清理费用');
            $table->string('buyer', 100)->comment('买方');
            $table->string('handler', 60)->nullable()->comment('经手人');
            $table->string('remark', 255)->nullable();
            $table->unsignedBigInteger('voucher_id')->nullable()->comment('处置凭证ID，null=未登记');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('sale_no');
            $table->index('asset_card_id');
            $table->index('sale_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_sale');
    }
};
