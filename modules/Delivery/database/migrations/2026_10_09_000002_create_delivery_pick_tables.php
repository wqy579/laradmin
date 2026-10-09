<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 拣货单表 + 拣货单明细表。由配货单确认后自动生成。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_pick')) {
            Schema::create('delivery_pick', function (Blueprint $table) {
                $table->id();
                $table->string('pick_no', 30)->unique()->comment('拣货单号 PJ+Ymd+6位');
                $table->unsignedBigInteger('picking_id')->comment('配货单ID');
                $table->string('picking_no', 30)->comment('配货单号快照');
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_name', 200)->nullable();
                $table->unsignedBigInteger('warehouse_id')->nullable()->comment('仓库ID');
                $table->date('pick_date')->comment('拣货日期');
                $table->unsignedBigInteger('picker_id')->nullable()->comment('拣货人(auth_user.id)');
                $table->string('picker_name', 50)->nullable();
                $table->integer('total_skus')->default(0)->comment('商品种类');
                $table->integer('total_qty')->default(0)->comment('商品总数');
                $table->integer('short_qty')->default(0)->comment('缺货总数');
                $table->string('status', 20)->default('pending')->comment('pending待拣货/picking拣货中/picked已拣货/cancelled已取消');
                $table->unsignedBigInteger('check_id')->nullable()->comment('生成的验货单ID');
                $table->text('remark')->nullable();
                $table->timestamps();

                $table->index('pick_no');
                $table->index('picking_id');
                $table->index('status');
                $table->index('pick_date');
            });
        }

        if (! Schema::hasTable('delivery_pick_items')) {
            Schema::create('delivery_pick_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('pick_id')->comment('拣货单ID');
                $table->unsignedBigInteger('picking_item_id')->nullable()->comment('配货单明细ID');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('product_code', 50)->nullable();
                $table->string('product_name', 200)->nullable();
                $table->string('spec', 100)->nullable();
                $table->string('unit', 20)->nullable();
                $table->integer('pick_qty')->default(0)->comment('应拣数量(=配货数量)');
                $table->integer('actual_qty')->default(0)->comment('实拣数量');
                $table->integer('short_qty')->default(0)->comment('缺货数量(=应拣-实拣)');
                $table->string('bin_location', 50)->nullable()->comment('货位(手填,系统无货位表)');
                $table->text('remark')->nullable();
                $table->integer('sort')->default(0);
                $table->timestamps();

                $table->index('pick_id');
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_pick_items');
        Schema::dropIfExists('delivery_pick');
    }
};
