<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 装车单表 + 装车单明细表。一个装车单可含多个客户的验货单。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_load')) {
            Schema::create('delivery_load', function (Blueprint $table) {
                $table->id();
                $table->string('load_no', 30)->unique()->comment('装车单号 LD+Ymd+6位');
                $table->date('load_date')->comment('装车日期');
                $table->unsignedBigInteger('delivery_person_id')->nullable()->comment('配送员(auth_user.id)');
                $table->string('delivery_person_name', 50)->nullable();
                $table->unsignedBigInteger('employee_id')->nullable()->comment('配送员员工档案(employees.id,冗余)');
                $table->unsignedBigInteger('vehicle_id')->nullable()->comment('车辆ID');
                $table->string('plate_no', 30)->nullable()->comment('车牌号');
                $table->integer('order_count')->default(0)->comment('订单数量');
                $table->integer('total_skus')->default(0)->comment('商品种类');
                $table->integer('total_qty')->default(0)->comment('商品总数');
                $table->decimal('total_amount', 14, 2)->default(0)->comment('金额');
                $table->string('status', 20)->default('pending')->comment('pending待装车/loaded已装车/delivering配送中/completed已完成');
                $table->text('remark')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('creator_name', 50)->nullable();
                $table->timestamp('loaded_at')->nullable()->comment('确认装车时间');
                $table->timestamps();

                $table->index('load_no');
                $table->index('delivery_person_id');
                $table->index('employee_id');
                $table->index('status');
                $table->index('load_date');
            });
        }

        if (! Schema::hasTable('delivery_load_items')) {
            Schema::create('delivery_load_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('load_id')->comment('装车单ID');
                $table->unsignedBigInteger('check_id')->nullable()->comment('验货单ID');
                $table->string('check_no', 30)->nullable();
                $table->unsignedBigInteger('sales_order_id')->nullable()->comment('销售订单ID');
                $table->string('order_no', 30)->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_name', 200)->nullable();
                $table->string('address', 255)->nullable()->comment('送货地址');
                $table->string('phone', 30)->nullable()->comment('联系电话');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('product_code', 50)->nullable();
                $table->string('product_name', 200)->nullable();
                $table->string('spec', 100)->nullable();
                $table->string('unit', 20)->nullable();
                $table->integer('quantity')->default(0)->comment('数量(按验货实验数)');
                $table->decimal('price', 10, 2)->default(0);
                $table->decimal('amount', 14, 2)->default(0);
                $table->unsignedBigInteger('picking_id')->nullable()->comment('配货单ID(用于回溯解冻)');
                $table->text('remark')->nullable();
                $table->integer('sort')->default(0);
                $table->timestamps();

                $table->index('load_id');
                $table->index('check_id');
                $table->index('sales_order_id');
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_load_items');
        Schema::dropIfExists('delivery_load');
    }
};
