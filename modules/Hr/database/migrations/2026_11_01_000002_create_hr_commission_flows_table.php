<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4 提成流水（按期间从 perf 域提成单同步的只读快照）。
 *
 * perf_commission_orders 属 C 域、本任务只读禁改；发放动作把已发放提成单
 * 逐单落成 hr 侧流水，供「提成流水」页按 业务员/期间/单据 查询，
 * 金额逐列复制 perf 口径（应发 commission_amount / 实发 actual_amount）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_commission_flows')) {
            Schema::create('hr_commission_flows', function (Blueprint $table) {
                $table->id();
                $table->string('flow_no', 50)->unique()->comment('流水号 TF+日期+序号');
                $table->unsignedBigInteger('commission_order_id')->nullable()->comment('perf 提成单ID');
                $table->string('bill_no', 50)->comment('提成单号快照');
                $table->string('period', 7)->comment('提成期间 YYYY-MM');
                $table->unsignedBigInteger('employee_id')->comment('业务员ID（users.id）');
                $table->string('employee_name', 50)->comment('业务员姓名快照');
                $table->unsignedBigInteger('department_id')->nullable()->comment('部门ID快照');
                $table->string('department_name', 50)->nullable()->comment('部门名称快照');
                $table->string('source_no', 50)->nullable()->comment('源单据号（提成明细首单，用于按单据查询）');
                $table->decimal('sales_amount', 14, 2)->default(0)->comment('销售额');
                $table->decimal('received_amount', 14, 2)->default(0)->comment('回款额');
                $table->decimal('base_amount', 14, 2)->default(0)->comment('计提基数');
                $table->decimal('commission_amount', 14, 2)->default(0)->comment('应发提成（perf 口径）');
                $table->decimal('deduction', 14, 2)->default(0)->comment('扣减');
                $table->decimal('actual_amount', 14, 2)->default(0)->comment('实发提成（perf 口径）');
                $table->unsignedBigInteger('payroll_id')->nullable()->comment('关联工资单ID');
                $table->string('payroll_no', 50)->nullable()->comment('工资单号回链');
                $table->date('paid_date')->nullable()->comment('发放日期');
                $table->text('remark')->nullable()->comment('备注');
                $table->timestamps();

                $table->unique('commission_order_id', 'hr_commission_flows_order_unique');
                $table->index('employee_id');
                $table->index('period');
                $table->index('source_no');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_commission_flows');
    }
};
