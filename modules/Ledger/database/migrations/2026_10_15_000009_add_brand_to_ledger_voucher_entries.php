<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 总账管理 · 凭证分录增加「品牌」辅助核算列
 *
 * 分录在既有 aux_*（客户/供应商）之外独立一维：品牌。
 * 客户/供应商已由 subject 的 aux_types 驱动占住 aux_* 槽位，
 * 品牌是每个科目都可选的全局字典（brands 表），故单独落 brand_id/brand_name。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_voucher_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('brand_id')->nullable()->after('aux_name')->comment('品牌 id（brands 表）');
            $table->string('brand_name', 80)->nullable()->after('brand_id')->comment('冗余品牌名称');

            $table->index('brand_id');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_voucher_entries', function (Blueprint $table) {
            $table->dropIndex(['brand_id']);
            $table->dropColumn(['brand_id', 'brand_name']);
        });
    }
};