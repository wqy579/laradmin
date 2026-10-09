<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 车上退仓（VanReturnToWarehouse）：车辆把车上库存退回普通仓库。
 *
 * 与现有 van_return_orders（VXT，客户现场退货回车上库存）方向相反——
 * 本表是「车 → 仓库」的反向调拨，用于滞销/临期商品从车上退回，避免车上库存成为死库存。
 *
 * 状态机（与 stage-2 单据一致）：draft → approved/cancelled，approved 不可逆。
 * 库存移动发生在 approve 时：车上仓 stockOut + 目的普通仓 stockIn，无 freeze/unfreeze
 * （freeze 只守仓库→车方向；反向退仓直接移库）。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 车上退仓主表（VRW）
        if (! Schema::hasTable('van_return_to_warehouse')) {
            Schema::create('van_return_to_warehouse', function (Blueprint $table) {
                $table->id();
                $table->string('return_no', 30)->unique()->comment('退仓单号 VRW+Ymd+6位');
                $table->unsignedBigInteger('salesman_id')->nullable()->comment('业务员(auth_user.id)');
                $table->string('salesman_name', 50)->nullable();
                $table->unsignedBigInteger('vehicle_id')->nullable();
                $table->unsignedBigInteger('vehicle_warehouse_id')->nullable()->comment('车上仓ID(源)');
                $table->unsignedBigInteger('warehouse_id')->nullable()->comment('目的普通仓ID');
                $table->date('return_date');
                $table->integer('total_qty')->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->string('status', 20)->default('draft')->comment('draft/approved/cancelled');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('creator_name', 50)->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->string('approver_name', 50)->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('approval_comment')->nullable();
                $table->text('remark')->nullable();
                $table->timestamps();
                $table->index('return_no');
                $table->index('vehicle_warehouse_id');
                $table->index('warehouse_id');
                $table->index('status');
                $table->index('salesman_id');
            });
        }

        // 车上退仓明细
        if (! Schema::hasTable('van_return_to_warehouse_items')) {
            Schema::create('van_return_to_warehouse_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('product_id');
                $table->string('product_code', 50)->nullable();
                $table->string('product_name', 200)->nullable();
                $table->string('spec', 100)->nullable();
                $table->string('unit', 20)->nullable();
                $table->integer('return_qty')->default(0)->comment('退仓数量');
                $table->decimal('unit_cost', 10, 2)->default(0);
                $table->decimal('amount', 14, 2)->default(0);
                $table->text('remark')->nullable();
                $table->integer('sort')->default(0);
                $table->timestamps();
                $table->index('order_id');
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('van_return_to_warehouse_items');
        Schema::dropIfExists('van_return_to_warehouse');
    }
};
