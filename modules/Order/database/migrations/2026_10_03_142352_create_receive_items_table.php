<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('receive_items')) {
            Schema::create('receive_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('receive_id')->comment('收款单ID');
                $table->unsignedBigInteger('sales_order_id')->comment('销售订单ID');
                $table->string('order_no', 50)->nullable()->comment('订单号快照');
                $table->decimal('pay_amount', 14, 2)->default(0)->comment('本次核销金额');
                $table->decimal('paid_before', 14, 2)->default(0)->comment('核销前已收金额');
                $table->decimal('receivable_before', 14, 2)->default(0)->comment('核销前应收金额');
                $table->timestamps();

                $table->index('receive_id');
                $table->index('sales_order_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('receive_items');
    }
};
