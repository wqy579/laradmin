<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 配送任务表。由装车单确认后自动生成，一验货单一任务。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_task')) {
            Schema::create('delivery_task', function (Blueprint $table) {
                $table->id();
                $table->string('task_no', 30)->unique()->comment('任务单号 RW+Ymd+6位');
                $table->unsignedBigInteger('load_id')->comment('装车单ID');
                $table->string('load_no', 30)->comment('装车单号快照');
                $table->unsignedBigInteger('check_id')->nullable()->comment('验货单ID');
                $table->string('check_no', 30)->nullable();
                $table->unsignedBigInteger('sales_order_id')->nullable()->comment('销售订单ID');
                $table->string('order_no', 30)->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_name', 200)->nullable();
                $table->string('address', 255)->nullable()->comment('送货地址');
                $table->string('phone', 30)->nullable()->comment('联系电话');
                $table->unsignedBigInteger('warehouse_id')->nullable()->comment('仓库ID(回溯解冻用)');
                $table->unsignedBigInteger('delivery_person_id')->nullable()->comment('配送员(auth_user.id)');
                $table->string('delivery_person_name', 50)->nullable();
                $table->unsignedBigInteger('employee_id')->nullable()->comment('员工档案ID(冗余)');
                $table->unsignedBigInteger('vehicle_id')->nullable();
                $table->string('plate_no', 30)->nullable();
                $table->integer('total_skus')->default(0);
                $table->integer('total_qty')->default(0);
                $table->decimal('order_amount', 14, 2)->default(0)->comment('订单金额(应收)');
                $table->decimal('paid_amount', 14, 2)->default(0)->comment('已收金额');
                $table->decimal('unpaid_amount', 14, 2)->default(0)->comment('待收金额');
                $table->string('status', 20)->default('pending')->comment('pending待配送/delivering配送中/delivered已送达/paid已收款/exception异常');
                $table->string('exception_type', 20)->nullable()->comment('reject拒收/damaged破损/address地址错误');
                $table->text('exception_remark')->nullable();
                $table->timestamp('started_at')->nullable()->comment('开始配送时间');
                $table->timestamp('delivered_at')->nullable()->comment('送达时间');
                $table->timestamp('collected_at')->nullable()->comment('收款完成时间');
                $table->text('remark')->nullable();
                $table->timestamps();

                $table->index('task_no');
                $table->index('load_id');
                $table->index('delivery_person_id');
                $table->index('customer_id');
                $table->index('sales_order_id');
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_task');
    }
};
