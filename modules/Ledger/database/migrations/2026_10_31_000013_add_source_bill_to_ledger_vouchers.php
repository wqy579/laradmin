<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 总账管理 · 记账凭证增加「来源业务单据」溯源列（QA-05）
 *
 * 业务单据（销售出库/收款/付款/费用/库存调整）自动生成凭证后，凭证要能回答
 * 「我是哪张单据生成的」，同时给生成器一个幂等凭据：同一张单据重复触发生成时
 * 直接返回既有凭证，不再重复出一套借贷。
 *
 * 溯源放在 ledger_vouchers 侧而不是给 5 张业务表各加 voucher_id：
 *  - 业务表属于其他机器/其他模块负责的表，加列要跨 5 处迁移，且生成能力一旦
 *    调整就得回改业务表结构；
 *  - 幂等约束由 Ledger 自治（唯一索引落在这里），业务侧只负责「调一次、失败不阻断」。
 *
 * 注：销售出库会生成「收入凭证 + 成本凭证」两张，故唯一索引必须容纳同一单据的
 * 多张凭证 —— 用 (source_type, source_id, source_seq) 三列唯一，seq 从 1 开始。
 * MySQL 的 (a,b,c) 唯一索引里 c 为 NULL 时不参与去重，所以 seq 必须 NOT NULL 默认 1，
 * 否则历史/手工凭证（source_* 全 NULL）之间无法互相去重，反而会被误判重复。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_vouchers', function (Blueprint $table) {
            $table->string('source_type', 30)->nullable()->after('remark')->comment('来源单据类型 delivery/receive/pay/expense/stock_adjust，手工凭证为 NULL');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type')->comment('来源单据主键 id');
            // 不调 ->nullable()：Laravel 建列默认 NOT NULL，这里必须是 NOT NULL（见类注释）
            $table->unsignedTinyInteger('source_seq')->default(1)->after('source_id')->comment('同一单据的第几张凭证（销售出库=1收入/2成本）');
        });

        // 幂等凭据：一张单据的一个序号只能有一张凭证
        Schema::table('ledger_vouchers', function (Blueprint $table) {
            $table->unique(['source_type', 'source_id', 'source_seq'], 'ledger_vouchers_source_unique');
        });

        // 结账检查③按「某类型是否有已生成凭证」聚合，走同一索引的前缀
        Schema::table('ledger_vouchers', function (Blueprint $table) {
            $table->index('source_type', 'ledger_vouchers_source_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_vouchers', function (Blueprint $table) {
            $table->dropIndex('ledger_vouchers_source_type_index');
            $table->dropUnique('ledger_vouchers_source_unique');
            $table->dropColumn(['source_type', 'source_id', 'source_seq']);
        });
    }
};
