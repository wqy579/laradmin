<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 采购申请（PurchaseApplication）模块表结构
 *
 * purchase_applications        采购申请主表
 * purchase_application_items    采购明细（三档数量/单价 + 折算快照）
 *
 * 状态机：draft → pending → approved / rejected / cancelled / transferred
 *   - rejected 编辑保存后回到 draft（用户决策）
 *   - transferred 为终态，转采购入库后不可再操作
 *
 * 与采购退货（purchase_returns）的区别：
 *   - 退货是从原入库单选商品退货，申请是自由录入全商品计划
 *   - 退货 approve 即出库；申请 approve 不动库存，独立 transfer 动作转入库
 *   - 退货冲减应付（suppliers.balance decrement），转入库增加应付（increment）
 *
 * 单号：CGSQ + Ymd + 6位流水（复用 StockAdjustController::generateNo 模式）
 * 单位换算：三档 qty_large/qty_medium/qty_small + price_large/price_medium/price_small，
 *          折算 quantity = 大×unit_conversion + 中×unit_conversion_medium + 小（对齐 StockInController::computeItemQtyAmount）
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_applications')) {
            Schema::create('purchase_applications', function (Blueprint $table) {
                $table->id();
                $table->string('apply_no', 30)->unique()->comment('申请单号 CGSQ+Ymd+6位');
                $table->unsignedBigInteger('supplier_id')->comment('供应商ID');
                $table->string('supplier_name', 100)->nullable()->comment('供应商名快照');
                $table->unsignedBigInteger('warehouse_id')->nullable()->comment('转入库目标仓库ID');
                $table->date('apply_date')->comment('制单日期');
                $table->date('expected_date')->nullable()->comment('需用日期');
                $table->string('payment_type', 20)->default('cash')->comment('付款类型: cash现金/transfer转账/monthly月结/other其他');
                $table->unsignedBigInteger('approver_id')->nullable()->comment('指定审批人(employees.id)');
                $table->string('approver_name', 50)->nullable()->comment('审批人姓名快照');
                $table->string('status', 20)->default('draft')->comment('draft/pending/approved/rejected/cancelled/transferred');
                $table->integer('total_skus')->default(0)->comment('明细行数');
                $table->integer('total_qty_large')->default(0)->comment('大单位数量合计');
                $table->integer('total_qty_medium')->default(0)->comment('中单位数量合计');
                $table->integer('total_qty_small')->default(0)->comment('小单位数量合计');
                $table->integer('total_quantity')->default(0)->comment('折算小单位总数量');
                $table->decimal('total_amount', 14, 2)->default(0)->comment('总金额');
                $table->decimal('payable_amount', 14, 2)->default(0)->comment('转入库生成的应付金额快照');
                $table->json('attachment')->nullable()->comment('附件元数据数组[{id,name,url,size,mime}]');
                $table->text('remark')->nullable()->comment('备注');
                $table->unsignedBigInteger('created_by')->nullable()->comment('制单人(auth_user.id)');
                $table->string('creator_name', 50)->nullable()->comment('制单人姓名快照');
                $table->unsignedBigInteger('approved_by')->nullable()->comment('实际审批人(auth_user.id)');
                $table->timestamp('approved_at')->nullable()->comment('审批时间');
                $table->text('approval_comment')->nullable()->comment('审批意见/驳回原因');
                $table->unsignedBigInteger('transferred_by')->nullable()->comment('转入库操作人(auth_user.id)');
                $table->timestamp('transferred_at')->nullable()->comment('转入库时间');
                $table->timestamps();

                $table->index('apply_no');
                $table->index('supplier_id');
                $table->index('status');
                $table->index('apply_date');
                $table->index('approver_id');
                $table->index('created_by');
            });
        }

        if (! Schema::hasTable('purchase_application_items')) {
            Schema::create('purchase_application_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('application_id')->comment('采购申请ID');
                $table->unsignedBigInteger('product_id')->comment('商品ID');
                $table->string('product_code', 50)->nullable()->comment('商品编码快照');
                $table->string('product_name', 200)->nullable()->comment('商品名称快照');
                $table->string('spec', 100)->nullable()->comment('规格快照');
                $table->string('unit_large', 20)->nullable()->comment('大单位名快照');
                $table->string('unit_medium', 20)->nullable()->comment('中单位名快照');
                $table->string('unit_small', 20)->nullable()->comment('小单位名快照');
                $table->integer('unit_conversion')->default(0)->comment('大→小倍数快照');
                $table->integer('unit_conversion_medium')->default(0)->comment('中→小倍数快照');
                $table->integer('qty_large')->default(0)->comment('大单位数量');
                $table->integer('qty_medium')->default(0)->comment('中单位数量');
                $table->integer('qty_small')->default(0)->comment('小单位数量');
                $table->decimal('price_large', 10, 2)->default(0)->comment('大单位单价');
                $table->decimal('price_medium', 10, 2)->default(0)->comment('中单位单价');
                $table->decimal('price_small', 10, 2)->default(0)->comment('小单位单价');
                $table->integer('quantity')->default(0)->comment('折算小单位总量');
                $table->decimal('amount', 14, 2)->default(0)->comment('金额=三档数量×单价之和');
                $table->decimal('cost_price', 10, 2)->default(0)->comment('主单位成本价(大>中>小取第一个非零)');
                $table->text('remark')->nullable()->comment('行备注');
                $table->integer('sort')->default(0)->comment('排序号');
                $table->timestamps();

                $table->index('application_id');
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_application_items');
        Schema::dropIfExists('purchase_applications');
    }
};
