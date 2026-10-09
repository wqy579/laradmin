<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 车销业务阶段一表结构：要货申请 + 拣货(含验货) + 车销销售单。
 *
 * 状态机：
 *   van_requisitions:  draft→pending→approved→picked(拣货完成终态) / rejected→draft(可编辑) / cancelled
 *   van_picking:       draft→pending→approved(装车完成) / cancelled；approved 后 checked 由验货推进
 *   van_sale_orders:   draft→approved(扣车上库存+收款，不可逆) / cancelled；冲正走退货单
 *
 * 车上库存复用 stocks + stocks_history，按 warehouse_id(type='vehicle' 的车辆仓) 查询，
 * 不在本迁移建独立库存表。related_type 用 'VanPicking'/'VanSaleOrder' 等标记来源。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 要货申请主表（VHQ）
        if (! Schema::hasTable('van_requisitions')) {
            Schema::create('van_requisitions', function (Blueprint $table) {
                $table->id();
                $table->string('requisition_no', 32)->unique()->comment('要货单号 VHQ+Ymd+6位');
                $table->unsignedBigInteger('salesman_id')->nullable()->comment('业务员(auth_user.id)');
                $table->string('salesman_name', 50)->nullable()->comment('业务员姓名快照');
                $table->unsignedBigInteger('vehicle_id')->nullable()->comment('车辆ID');
                $table->unsignedBigInteger('warehouse_id')->nullable()->comment('源仓库ID(type=normal)');
                $table->date('apply_date')->comment('申请日期');
                $table->date('expected_date')->nullable()->comment('需用日期');
                $table->string('status', 20)->default('draft')->comment('draft/pending/approved/picked/rejected/cancelled');
                $table->integer('total_qty')->default(0)->comment('商品总数');
                $table->decimal('total_amount', 14, 2)->default(0)->comment('总金额');
                $table->unsignedBigInteger('created_by')->nullable()->comment('制单人(auth_user.id)');
                $table->string('creator_name', 50)->nullable()->comment('制单人姓名快照');
                $table->unsignedBigInteger('approved_by')->nullable()->comment('审核人(auth_user.id)');
                $table->string('approver_name', 50)->nullable()->comment('审核人姓名快照');
                $table->timestamp('approved_at')->nullable()->comment('审核时间');
                $table->text('approval_comment')->nullable()->comment('审核意见/驳回原因');
                $table->text('remark')->nullable()->comment('备注');
                $table->timestamps();

                $table->index('requisition_no');
                $table->index('vehicle_id');
                $table->index('warehouse_id');
                $table->index('status');
                $table->index('salesman_id');
            });
        }

        // 要货申请明细
        if (! Schema::hasTable('van_requisition_items')) {
            Schema::create('van_requisition_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('requisition_id')->comment('要货申请ID');
                $table->unsignedBigInteger('product_id')->comment('商品ID');
                $table->string('product_code', 50)->nullable()->comment('商品编码快照');
                $table->string('product_name', 200)->nullable()->comment('商品名称快照');
                $table->string('spec', 100)->nullable()->comment('规格快照');
                $table->string('unit', 20)->nullable()->comment('单位快照');
                $table->integer('apply_qty')->default(0)->comment('申请数量');
                $table->integer('stock_qty')->default(0)->comment('申请时源仓库存快照');
                $table->decimal('unit_cost', 10, 2)->default(0)->comment('单位成本');
                $table->decimal('amount', 14, 2)->default(0)->comment('金额');
                $table->text('remark')->nullable()->comment('行备注');
                $table->integer('sort')->default(0)->comment('排序号');
                $table->timestamps();

                $table->index('requisition_id');
                $table->index('product_id');
            });
        }

        // 拣货单主表（VHP）
        if (! Schema::hasTable('van_picking')) {
            Schema::create('van_picking', function (Blueprint $table) {
                $table->id();
                $table->string('picking_no', 32)->unique()->comment('拣货单号 VHP+Ymd+6位');
                $table->unsignedBigInteger('requisition_id')->comment('要货申请ID');
                $table->unsignedBigInteger('warehouse_id')->nullable()->comment('源仓库ID');
                $table->unsignedBigInteger('vehicle_id')->nullable()->comment('车辆ID');
                $table->unsignedBigInteger('vehicle_warehouse_id')->nullable()->comment('车上仓ID(type=vehicle的warehouse)');
                $table->unsignedBigInteger('picker_id')->nullable()->comment('库管员(auth_user.id)');
                $table->string('picker_name', 50)->nullable()->comment('库管员姓名快照');
                $table->date('pick_date')->nullable()->comment('拣货日期');
                $table->string('status', 20)->default('draft')->comment('draft/pending/approved/cancelled');
                $table->boolean('checked')->default(false)->comment('是否已验货');
                $table->unsignedBigInteger('checker_id')->nullable()->comment('验货员(auth_user.id)');
                $table->string('checker_name', 50)->nullable()->comment('验货员姓名快照');
                $table->timestamp('checked_at')->nullable()->comment('验货时间');
                $table->integer('total_qty')->default(0)->comment('实际拣货总数');
                $table->text('remark')->nullable()->comment('备注');
                $table->timestamps();

                $table->index('picking_no');
                $table->index('requisition_id');
                $table->index('vehicle_warehouse_id');
                $table->index('status');
            });
        }

        // 拣货单明细（合并验货字段，不单独建验货表）
        if (! Schema::hasTable('van_picking_items')) {
            Schema::create('van_picking_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('picking_id')->comment('拣货单ID');
                $table->unsignedBigInteger('product_id')->comment('商品ID');
                $table->string('product_code', 50)->nullable()->comment('商品编码快照');
                $table->string('product_name', 200)->nullable()->comment('商品名称快照');
                $table->string('spec', 100)->nullable()->comment('规格快照');
                $table->string('unit', 20)->nullable()->comment('单位快照');
                $table->integer('apply_qty')->default(0)->comment('申请数量(来自要货)');
                $table->integer('pick_qty')->default(0)->comment('实际拣货数量');
                $table->integer('check_qty')->nullable()->comment('验货实收数量');
                $table->integer('diff_qty')->default(0)->comment('差异数量(check_qty-pick_qty)');
                $table->string('diff_remark', 500)->nullable()->comment('差异备注(差异≠0时必填)');
                $table->string('location', 50)->nullable()->comment('货位');
                $table->decimal('unit_cost', 10, 2)->default(0)->comment('单位成本');
                $table->decimal('amount', 14, 2)->default(0)->comment('金额');
                $table->integer('sort')->default(0)->comment('排序号');
                $table->timestamps();

                $table->index('picking_id');
                $table->index('product_id');
            });
        }

        // 车销销售单主表（VXS）
        if (! Schema::hasTable('van_sale_orders')) {
            Schema::create('van_sale_orders', function (Blueprint $table) {
                $table->id();
                $table->string('order_no', 30)->unique()->comment('销售单号 VXS+Ymd+6位');
                $table->unsignedBigInteger('salesman_id')->nullable()->comment('业务员(auth_user.id)');
                $table->string('salesman_name', 50)->nullable()->comment('业务员姓名快照');
                $table->unsignedBigInteger('customer_id')->nullable()->comment('客户ID');
                $table->string('customer_name', 200)->nullable()->comment('客户名快照');
                $table->unsignedBigInteger('vehicle_id')->nullable()->comment('车辆ID');
                $table->unsignedBigInteger('vehicle_warehouse_id')->nullable()->comment('车上仓ID(type=vehicle)');
                $table->date('sale_date')->comment('销售日期');
                $table->integer('total_qty')->default(0)->comment('商品总数');
                $table->decimal('total_amount', 14, 2)->default(0)->comment('总金额');
                $table->decimal('paid_amount', 14, 2)->default(0)->comment('收款金额');
                $table->string('payment_method', 20)->default('cash')->comment('现金/微信/支付宝/银行卡/挂账');
                $table->string('status', 20)->default('draft')->comment('draft/approved/cancelled');
                $table->unsignedBigInteger('visit_log_id')->nullable()->comment('关联拜访单ID');
                $table->unsignedBigInteger('created_by')->nullable()->comment('制单人(auth_user.id)');
                $table->string('creator_name', 50)->nullable()->comment('制单人姓名快照');
                $table->timestamp('approved_at')->nullable()->comment('确认销售时间');
                $table->text('remark')->nullable()->comment('备注');
                $table->timestamps();

                $table->index('order_no');
                $table->index('customer_id');
                $table->index('vehicle_warehouse_id');
                $table->index('status');
                $table->index('salesman_id');
            });
        }

        // 车销销售单明细
        if (! Schema::hasTable('van_sale_order_items')) {
            Schema::create('van_sale_order_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->comment('销售单ID');
                $table->unsignedBigInteger('product_id')->comment('商品ID');
                $table->string('product_code', 50)->nullable()->comment('商品编码快照');
                $table->string('product_name', 200)->nullable()->comment('商品名称快照');
                $table->string('spec', 100)->nullable()->comment('规格快照');
                $table->string('unit', 20)->nullable()->comment('单位快照');
                $table->integer('stock_qty')->default(0)->comment('车上库存快照');
                $table->integer('sale_qty')->default(0)->comment('销售数量');
                $table->decimal('unit_price', 10, 2)->default(0)->comment('单价');
                $table->string('price_source', 30)->nullable()->comment('价格来源(PriceService返回)');
                $table->decimal('amount', 14, 2)->default(0)->comment('金额');
                $table->text('remark')->nullable()->comment('行备注');
                $table->integer('sort')->default(0)->comment('排序号');
                $table->timestamps();

                $table->index('order_id');
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('van_sale_order_items');
        Schema::dropIfExists('van_sale_orders');
        Schema::dropIfExists('van_picking_items');
        Schema::dropIfExists('van_picking');
        Schema::dropIfExists('van_requisition_items');
        Schema::dropIfExists('van_requisitions');
    }
};
