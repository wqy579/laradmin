<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('red_flush_reason', 500)->nullable()->comment('红冲原因')->after('remark');
            $table->unsignedBigInteger('red_flush_by')->nullable()->comment('红冲人ID')->after('red_flush_reason');
            $table->timestamp('red_flush_at')->nullable()->comment('红冲时间')->after('red_flush_by');
            $table->unsignedBigInteger('red_flush_order_id')->nullable()->comment('关联改单后的新订单ID')->after('red_flush_at');
            $table->unsignedBigInteger('original_order_id')->nullable()->comment('原订单ID(改单生成的新单回溯用)')->after('red_flush_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['red_flush_reason', 'red_flush_by', 'red_flush_at', 'red_flush_order_id', 'original_order_id']);
        });
    }
};
