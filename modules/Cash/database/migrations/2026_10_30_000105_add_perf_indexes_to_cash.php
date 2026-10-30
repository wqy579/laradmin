<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 出纳模块 · 补齐被实际筛选却未建索引的列（性能加固）
 *
 * 审计发现以下列被 where 条件实际过滤，但未建索引：
 *  - cash_card_txn.salesman_id：刷卡记录按业务员筛选
 *  - cash_handover.salesman_id：上交款按上交人筛选
 *  - cash_handover.account_id：上交款按收款账户筛选
 *
 * 新增独立迁移（不修改已有建表迁移，保持 migrate:fresh 幂等）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_card_txn', function (Blueprint $table) {
            $table->index('salesman_id');
        });
        Schema::table('cash_handover', function (Blueprint $table) {
            $table->index('salesman_id');
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::table('cash_card_txn', function (Blueprint $table) {
            $table->dropIndex(['salesman_id']);
        });
        Schema::table('cash_handover', function (Blueprint $table) {
            $table->dropIndex(['salesman_id']);
            $table->dropIndex(['account_id']);
        });
    }
};
