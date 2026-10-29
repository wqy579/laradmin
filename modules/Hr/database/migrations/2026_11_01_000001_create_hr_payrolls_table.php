<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4 工资核算单（每人每期一张）。
 *
 * 应发净额 net_amount = base_amount 基本工资 + commission_amount 提成
 * + performance_amount 绩效 − deduction_amount 扣项，落库冗余存储，
 * 由服务层统一计算，避免 SQL 端各处重算口径漂移。
 * 提成列取自 perf 域提成单（只读快照），发放后状态锁定。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_payrolls')) {
            Schema::create('hr_payrolls', function (Blueprint $table) {
                $table->id();
                $table->string('payroll_no', 50)->unique()->comment('工资单号 GZ+日期+序号');
                $table->string('period', 7)->comment('工资期间 YYYY-MM');
                $table->unsignedBigInteger('employee_id')->comment('业务员ID（users.id，同 perf 口径）');
                $table->string('employee_name', 50)->comment('业务员姓名快照');
                $table->unsignedBigInteger('department_id')->nullable()->comment('部门ID快照');
                $table->string('department_name', 50)->nullable()->comment('部门名称快照');
                $table->decimal('base_amount', 12, 2)->default(0)->comment('基本工资');
                $table->decimal('commission_amount', 12, 2)->default(0)->comment('提成（perf 实发口径快照）');
                $table->decimal('performance_amount', 12, 2)->default(0)->comment('绩效');
                $table->decimal('deduction_amount', 12, 2)->default(0)->comment('扣项');
                $table->decimal('net_amount', 12, 2)->default(0)->comment('应发净额=基本+提成+绩效-扣项');
                $table->string('status', 20)->default('draft')->comment('draft草稿/paid已发放(锁定)');
                $table->unsignedBigInteger('pay_id')->nullable()->comment('付款单ID（pays.id）');
                $table->string('pay_no', 50)->nullable()->comment('付款单号回链');
                $table->string('cash_flow_no', 50)->nullable()->comment('资金流水号回链');
                $table->date('paid_date')->nullable()->comment('发放日期');
                $table->string('payment_method', 50)->nullable()->comment('支付方式');
                $table->timestamp('paid_at')->nullable()->comment('发放时间');
                $table->unsignedBigInteger('paid_by')->nullable()->comment('发放人');
                $table->text('remark')->nullable()->comment('备注');
                $table->timestamps();

                $table->unique(['period', 'employee_id'], 'hr_payrolls_period_employee_unique');
                $table->index('period');
                $table->index('employee_id');
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payrolls');
    }
};
