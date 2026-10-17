<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 凭证红冲（红字冲销）与「录单时自动审核」所需字段。
 *
 * - red_of_id : 红冲单指向原凭证 id（原凭证本身为 null）。
 * - is_red    : 该凭证已被红冲过。沿用 status 4 值枚举不变，红冲展示态用冗余列，
 *               避免扩展枚举影响现有 审核/反审核/作废 分支。
 * - auto_audit: 录单时自动审核开关，开启则保存即 status=audited（制单人自审入账）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_vouchers', function (Blueprint $table) {
            $table->unsignedBigInteger('red_of_id')->nullable()->after('remark')->comment('红冲单指向的原凭证 id');
            $table->boolean('is_red')->default(false)->after('red_of_id')->comment('已被红冲过');
            $table->boolean('auto_audit')->default(false)->after('is_red')->comment('录单时自动审核');
            $table->index('red_of_id');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_vouchers', function (Blueprint $table) {
            $table->dropIndex(['red_of_id']);
            $table->dropColumn(['red_of_id', 'is_red', 'auto_audit']);
        });
    }
};
