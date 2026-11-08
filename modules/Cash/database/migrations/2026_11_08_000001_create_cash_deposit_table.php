<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 存取款转账单（cash_deposit）
 *
 * 单据本身不记余额，保存时同步写 cash_flow 流水（related_type=cash_deposit），
 * 余额口径与现金账户查询一致：opening_balance + Σincome − Σexpense。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_deposit', function (Blueprint $table) {
            $table->id();
            $table->string('doc_no', 50)->unique()->comment('单据号，前缀 CQ');
            $table->date('biz_date')->comment('业务日期');
            $table->string('type', 20)->comment('deposit 存款 / withdraw 取款 / transfer 互转');
            $table->unsignedBigInteger('account_id')->comment('资金账户');
            $table->unsignedBigInteger('to_account_id')->nullable()->comment('互转转入账户');
            $table->string('counterpart', 100)->nullable()->comment('往来单位');
            $table->decimal('amount', 14, 2)->comment('金额');
            $table->decimal('fee', 14, 2)->default(0)->comment('手续费');
            $table->string('handler', 50)->nullable()->comment('经办人');
            $table->string('department', 50)->nullable()->comment('部门');
            $table->string('remark', 255)->nullable()->comment('摘要');
            $table->string('memo', 255)->nullable()->comment('备注');
            $table->string('status', 20)->default('confirmed')->comment('单据状态');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人');
            $table->timestamps();

            $table->index(['account_id', 'biz_date']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_deposit');
    }
};
