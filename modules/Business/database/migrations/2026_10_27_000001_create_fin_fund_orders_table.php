<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * B4 资金单族（6 类）统一单据表。
     *
     * 一张表承载 6 类资金单据：欠条确认 / 借款还款 / 资金增减 / 应收应付调整 /
     * 客户余额调整 / 供应商余额调整。doc_type 作判别列，类型专属字段用可空列承载。
     * 本表即资金单据的「历史」留痕：每单保存即写入一行，叠加写 cash_flows 资金流水。
     */
    public function up(): void
    {
        if (Schema::hasTable('fin_fund_orders')) {
            return;
        }

        Schema::create('fin_fund_orders', function (Blueprint $table) {
            $table->id();
            $table->string('doc_type', 30)->comment('单据类型：iou/borrow_repay/fund_io/arap_adjust/customer_balance/supplier_balance');
            $table->string('doc_no', 50)->unique()->comment('单据编号');
            $table->string('partner_type', 20)->nullable()->comment('往来方类型：customer/supplier/employee');
            $table->unsignedBigInteger('partner_id')->nullable()->comment('往来方ID');
            $table->string('partner_name', 100)->nullable()->comment('往来方名称（冗余快照）');
            $table->decimal('amount', 14, 2)->default(0)->comment('金额，调整类/资金类可正可负（正=收/增，负=付/减）；借款还款单存剩余未还');
            $table->decimal('original_amount', 14, 2)->nullable()->comment('原始借款额（借款还款单用于判定部分偿还）');
            $table->date('doc_date')->comment('单据日期');
            $table->date('due_date')->nullable()->comment('约定还款日（欠条/借款）');
            $table->string('status', 20)->default('pending')->comment('状态：pending/confirmed/approved/rejected/settled');
            $table->string('direction', 10)->nullable()->comment('资金增减方向：increase/decrease');
            $table->string('account', 50)->nullable()->comment('资金账户（资金增减）');
            $table->string('payment_method', 50)->nullable()->default('现金')->comment('收付方式');
            $table->decimal('opening_balance', 14, 2)->nullable()->comment('期初余额（调整单三段之期初）');
            $table->decimal('current_balance', 14, 2)->nullable()->comment('当前余额（调整单三段之当前）');
            $table->decimal('adjusted_balance', 14, 2)->nullable()->comment('调整后余额（调整单三段之调整后）');
            $table->string('reason', 255)->nullable()->comment('调整原因（调整单必填红*）');
            $table->string('abstract', 255)->nullable()->comment('摘要');
            $table->string('reject_reason', 255)->nullable()->comment('驳回原因');
            $table->string('signature_url', 255)->nullable()->comment('签字图（欠条）');
            $table->json('items')->nullable()->comment('调整分录明细：subject/direction/amount/abstract');
            $table->unsignedBigInteger('voucher_id')->nullable()->comment('同步应收应付所记账凭证ID');
            $table->string('cash_flow_no', 50)->nullable()->comment('关联资金流水号');
            $table->unsignedBigInteger('created_by')->nullable()->comment('制单人ID');
            $table->timestamps();

            $table->index('doc_type');
            $table->index('status');
            $table->index('partner_id');
            $table->index('doc_date');
            $table->index('voucher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_fund_orders');
    }
};
