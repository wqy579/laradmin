<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 机器 C（绩效提成与经营分析模块）数据表。
 *
 * 表前缀 perf_，物理隔离于机器 A（ledger_）与机器 B（fin_）。
 * 本期只新建提成相关表（rules / orders / items / targets），
 * 分析报表一律实时查询现有业务表，不建物化副本。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 提成方案表
        if (! Schema::hasTable('perf_commission_rules')) {
            Schema::create('perf_commission_rules', function (Blueprint $table) {
                $table->id();
                $table->string('code', 30)->unique()->comment('方案编号');
                $table->string('name', 100)->comment('方案名称');
                $table->string('calc_type', 30)->default('sale_rate')->comment('sale_rate/profit_rate/fixed_per_unit/tiered/product_specific');
                $table->json('apply_employees')->nullable()->comment('适用业务员 user_id 数组，空=全部');
                $table->json('apply_customer_levels')->nullable()->comment('适用客户等级 id 数组，空=全部');
                $table->string('product_scope_type', 20)->default('all')->comment('all/category/brand/product');
                $table->json('product_scope_ids')->nullable()->comment('商品范围 id 数组');
                $table->decimal('rate', 6, 4)->default(0)->comment('比例（百分比，如 1.5 = 1.5%）');
                $table->string('unit_base', 20)->nullable()->comment('件/箱/最小单位');
                $table->decimal('unit_amount', 12, 4)->default(0)->comment('每件/每箱固定金额');
                $table->json('tier_data')->nullable()->comment('阶梯 JSON');
                $table->json('product_rules')->nullable()->comment('单品专属规则 JSON');
                $table->boolean('base_on_payment')->default(true)->comment('按回款金额计算');
                $table->boolean('deduct_return')->default(true)->comment('退货扣减提成');
                $table->boolean('exclude_baddebt')->default(true)->comment('坏账/未回款不计提');
                $table->decimal('cap_amount', 14, 2)->default(0)->comment('封顶提成，0=不封顶');
                $table->decimal('floor_amount', 14, 2)->default(0)->comment('保底提成');
                $table->boolean('allow_stack')->default(false)->comment('允许同一业务员叠加多方案');
                $table->date('start_date')->nullable()->comment('生效日期');
                $table->date('end_date')->nullable()->comment('失效日期');
                $table->string('status', 20)->default('active')->comment('active/inactive');
                $table->text('remark')->nullable();
                $table->timestamps();
            });
        }

        // 提成单主表
        if (! Schema::hasTable('perf_commission_orders')) {
            Schema::create('perf_commission_orders', function (Blueprint $table) {
                $table->id();
                $table->string('bill_no', 40)->unique()->comment('提成单号');
                $table->string('period', 10)->comment('年月 YYYY-MM');
                $table->unsignedBigInteger('employee_id')->nullable()->comment('业务员 user_id');
                $table->string('employee_name', 50)->nullable();
                $table->unsignedBigInteger('department_id')->nullable();
                $table->string('department_name', 50)->nullable();
                $table->decimal('sale_amount', 14, 2)->default(0)->comment('销售额');
                $table->decimal('payment_amount', 14, 2)->default(0)->comment('回款额');
                $table->decimal('return_amount', 14, 2)->default(0)->comment('退货金额');
                $table->decimal('gross_profit', 14, 2)->default(0)->comment('毛利');
                $table->decimal('commission_base', 14, 2)->default(0)->comment('提成基数');
                $table->decimal('commission_gross', 14, 2)->default(0)->comment('应发提成');
                $table->decimal('commission_deduct', 14, 2)->default(0)->comment('扣减');
                $table->decimal('commission_amount', 14, 2)->default(0)->comment('实发提成');
                $table->string('status', 20)->default('pending')->comment('pending/audited/paid/rejected');
                $table->json('detail_snapshot')->nullable()->comment('计算明细 JSON');
                $table->unsignedBigInteger('maker_id')->nullable()->comment('制单人');
                $table->string('maker_name', 50)->nullable();
                $table->unsignedBigInteger('auditor_id')->nullable()->comment('审核人');
                $table->string('auditor_name', 50)->nullable();
                $table->timestamp('audited_at')->nullable();
                $table->timestamp('paid_at')->nullable()->comment('发放时间');
                $table->text('remark')->nullable();
                $table->timestamps();

                $table->index(['period', 'status']);
                $table->index('employee_id');
            });
        }

        // 提成明细表
        if (! Schema::hasTable('perf_commission_order_items')) {
            Schema::create('perf_commission_order_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('commission_order_id');
                $table->string('source_type', 20)->comment('order/product_line');
                $table->unsignedBigInteger('source_id')->nullable()->comment('订单/商品行 id');
                $table->string('source_no', 40)->nullable()->comment('单据号快照');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('product_name', 200)->nullable();
                $table->decimal('qty', 14, 3)->default(0);
                $table->decimal('base_amount', 14, 2)->default(0)->comment('提成基数金额');
                $table->unsignedBigInteger('rule_id')->nullable()->comment('命中方案 id');
                $table->string('rule_name', 100)->nullable();
                $table->string('calc_type', 30)->nullable();
                $table->string('rate_or_unit', 50)->nullable()->comment('比例或单额文案');
                $table->decimal('amount', 14, 2)->default(0)->comment('该行提成金额');
                $table->timestamps();

                $table->index('commission_order_id');
            });
        }

        // 销售目标表（可选）
        if (! Schema::hasTable('perf_sales_targets')) {
            Schema::create('perf_sales_targets', function (Blueprint $table) {
                $table->id();
                $table->string('target_type', 20)->comment('employee/customer/product');
                $table->unsignedBigInteger('target_id');
                $table->string('period', 10)->comment('YYYY-MM');
                $table->decimal('target_amount', 14, 2)->default(0);
                $table->timestamps();

                $table->unique(['target_type', 'target_id', 'period']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('perf_sales_targets');
        Schema::dropIfExists('perf_commission_order_items');
        Schema::dropIfExists('perf_commission_orders');
        Schema::dropIfExists('perf_commission_rules');
    }
};
