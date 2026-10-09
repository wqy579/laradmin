<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 车销上交货款（VanRemit，VRM）：业务员把当日车销收取的货款上交出纳。
 *
 * 纯台账型设计（用户拍板）：
 *   - 不修改 VanSaleOrderController::approve 的资金流（其 cash_flows related_type=VanSaleOrder 保持不变）
 *   - 本表的 confirm 写一条 cash_flows（related_type=VanRemit），与 approve 的流水通过 related_type 区分
 *   - 待上交金额实时计算（已确认销售单 paid_amount 合计 − 已上交 confirmed 合计），不冗余存储
 *
 * 状态流转：pending（已上交待确认）→ confirmed/rejected
 * schema 镜像 delivery_remit（2026_10_09_000007）。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 上交货款主表（VRM）
        if (! Schema::hasTable('van_remit')) {
            Schema::create('van_remit', function (Blueprint $table) {
                $table->id();
                $table->string('remit_no', 30)->unique()->comment('上交单号 VRM+Ymd+6位');
                $table->unsignedBigInteger('salesman_id')->nullable()->comment('业务员(auth_user.id)');
                $table->string('salesman_name', 50)->nullable();
                $table->date('remit_date');
                $table->decimal('cash_amount', 14, 2)->default(0);
                $table->decimal('wechat_amount', 14, 2)->default(0);
                $table->decimal('alipay_amount', 14, 2)->default(0);
                $table->decimal('bank_amount', 14, 2)->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->string('status', 20)->default('pending')->comment('pending/confirmed/rejected');
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->text('reject_reason')->nullable();
                $table->text('remark')->nullable();
                $table->timestamps();
                $table->index('remit_no');
                $table->index('salesman_id');
                $table->index('status');
                $table->index('remit_date');
            });
        }

        // 上交货款明细（FIFO 关联到已确认销售单）
        if (! Schema::hasTable('van_remit_items')) {
            Schema::create('van_remit_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('remit_id');
                $table->unsignedBigInteger('sale_order_id')->nullable()->comment('关联 van_sale_orders.id');
                $table->string('sale_order_no', 30)->nullable();
                $table->string('payment_method', 20)->comment('中文: 现金/微信/支付宝/银行卡');
                $table->decimal('amount', 14, 2)->default(0);
                $table->timestamps();
                $table->index('remit_id');
                $table->index('sale_order_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('van_remit_items');
        Schema::dropIfExists('van_remit');
    }
};
