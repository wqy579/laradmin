<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 上交货款表 + 上交明细表。配送员按方式分类上交货款给出纳。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_remit')) {
            Schema::create('delivery_remit', function (Blueprint $table) {
                $table->id();
                $table->string('remit_no', 30)->unique()->comment('上交单号 SJ+Ymd+6位');
                $table->date('remit_date')->comment('上交日期');
                $table->unsignedBigInteger('delivery_person_id')->nullable()->comment('配送员(auth_user.id)');
                $table->string('delivery_person_name', 50)->nullable();
                $table->unsignedBigInteger('employee_id')->nullable()->comment('员工档案ID(冗余)');
                $table->decimal('cash_amount', 14, 2)->default(0)->comment('现金金额');
                $table->decimal('wechat_amount', 14, 2)->default(0)->comment('微信金额');
                $table->decimal('alipay_amount', 14, 2)->default(0)->comment('支付宝金额');
                $table->decimal('bank_amount', 14, 2)->default(0)->comment('银行卡金额');
                $table->decimal('total_amount', 14, 2)->default(0)->comment('合计');
                $table->string('status', 20)->default('pending')->comment('pending待上交/remitted已上交/confirmed已确认');
                $table->text('remark')->nullable();
                $table->unsignedBigInteger('confirmed_by')->nullable()->comment('出纳确认人(auth_user.id)');
                $table->timestamp('confirmed_at')->nullable()->comment('出纳确认时间');
                $table->timestamps();

                $table->index('remit_no');
                $table->index('delivery_person_id');
                $table->index('status');
                $table->index('remit_date');
            });
        }

        if (! Schema::hasTable('delivery_remit_items')) {
            Schema::create('delivery_remit_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('remit_id')->comment('上交单ID');
                $table->unsignedBigInteger('collection_id')->nullable()->comment('收款单ID');
                $table->string('collection_no', 30)->nullable();
                $table->string('payment_method', 20)->comment('收款方式');
                $table->decimal('amount', 14, 2)->default(0)->comment('本次上交金额');
                $table->timestamps();

                $table->index('remit_id');
                $table->index('collection_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_remit_items');
        Schema::dropIfExists('delivery_remit');
    }
};
