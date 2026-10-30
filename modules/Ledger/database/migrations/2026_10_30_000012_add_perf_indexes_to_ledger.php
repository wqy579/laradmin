<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 总账管理 · 补齐高频筛选/外键列索引（性能加固）
 *
 * 审计发现以下列被实际过滤条件使用（或为外键），但未建索引：
 *  - ledger_vouchers.maker_id / auditor_id：列表按制单人/审核人筛选
 *  - ledger_voucher_entries.aux_id：辅助核算对象常作查询条件
 *  - ledger_periods.closed_by：月结操作人
 *  - ledger_voucher_templates.creator_id：模板创建人
 *
 * 新增独立迁移（不修改已有建表迁移，保持 migrate:fresh 幂等）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_vouchers', function (Blueprint $table) {
            $table->index('maker_id');
            $table->index('auditor_id');
        });
        Schema::table('ledger_voucher_entries', function (Blueprint $table) {
            $table->index('aux_id');
        });
        Schema::table('ledger_periods', function (Blueprint $table) {
            $table->index('closed_by');
        });
        Schema::table('ledger_voucher_templates', function (Blueprint $table) {
            $table->index('creator_id');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_vouchers', function (Blueprint $table) {
            $table->dropIndex(['maker_id']);
            $table->dropIndex(['auditor_id']);
        });
        Schema::table('ledger_voucher_entries', function (Blueprint $table) {
            $table->dropIndex(['aux_id']);
        });
        Schema::table('ledger_periods', function (Blueprint $table) {
            $table->dropIndex(['closed_by']);
        });
        Schema::table('ledger_voucher_templates', function (Blueprint $table) {
            $table->dropIndex(['creator_id']);
        });
    }
};
