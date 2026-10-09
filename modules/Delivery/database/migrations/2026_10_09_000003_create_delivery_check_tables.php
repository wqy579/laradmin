<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 验货单表 + 验货单明细表。由拣货单确认后自动生成。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_check')) {
            Schema::create('delivery_check', function (Blueprint $table) {
                $table->id();
                $table->string('check_no', 30)->unique()->comment('验货单号 YH+Ymd+6位');
                $table->unsignedBigInteger('pick_id')->comment('拣货单ID');
                $table->string('pick_no', 30)->comment('拣货单号快照');
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_name', 200)->nullable();
                $table->date('check_date')->comment('验货日期');
                $table->unsignedBigInteger('checker_id')->nullable()->comment('验货人(auth_user.id)');
                $table->string('checker_name', 50)->nullable();
                $table->integer('total_skus')->default(0)->comment('商品种类');
                $table->integer('expected_qty')->default(0)->comment('应验数量');
                $table->integer('actual_qty')->default(0)->comment('实验数量');
                $table->integer('diff_qty')->default(0)->comment('差异数量');
                $table->string('status', 20)->default('pending')->comment('pending待验货/checking验货中/checked已验货/exception验货异常');
                $table->unsignedBigInteger('load_id')->nullable()->comment('关联装车单ID');
                $table->text('remark')->nullable();
                $table->timestamps();

                $table->index('check_no');
                $table->index('pick_id');
                $table->index('status');
                $table->index('check_date');
            });
        }

        if (! Schema::hasTable('delivery_check_items')) {
            Schema::create('delivery_check_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('check_id')->comment('验货单ID');
                $table->unsignedBigInteger('pick_item_id')->nullable()->comment('拣货单明细ID');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('product_code', 50)->nullable();
                $table->string('product_name', 200)->nullable();
                $table->string('spec', 100)->nullable();
                $table->string('unit', 20)->nullable();
                $table->integer('pick_qty')->default(0)->comment('拣货数量(应验)');
                $table->integer('actual_qty')->default(0)->comment('实验数量');
                $table->integer('diff_qty')->default(0)->comment('差异(=拣货-实验)');
                $table->text('remark')->nullable()->comment('差异备注(有差异必填)');
                $table->integer('sort')->default(0);
                $table->timestamps();

                $table->index('check_id');
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_check_items');
        Schema::dropIfExists('delivery_check');
    }
};
