<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_returns')) {
            Schema::create('purchase_returns', function (Blueprint $table) {
                $table->id();
                $table->string('return_no', 30)->unique()->comment('采购退货单号PR+Ymd+6位');
                $table->unsignedBigInteger('stock_in_id')->nullable()->comment('原入库单ID');
                $table->string('stock_in_no', 30)->nullable()->comment('原入库单号冗余');
                $table->unsignedBigInteger('supplier_id')->nullable()->comment('供应商ID');
                $table->string('supplier_name', 100)->nullable()->comment('供应商名冗余');
                $table->unsignedBigInteger('warehouse_id')->nullable()->comment('退货仓库ID');
                $table->date('return_date')->comment('退货日期');
                $table->string('status', 20)->default('draft')->comment('draft/pending/approved/cancelled');
                $table->integer('total_skus')->default(0);
                $table->integer('total_qty')->default(0)->comment('退货总数量');
                $table->decimal('total_amount', 14, 2)->default(0)->comment('退货总金额');
                $table->decimal('payable_offset', 14, 2)->default(0)->comment('冲减应付金额');
                $table->string('contact', 50)->nullable();
                $table->string('phone', 20)->nullable();
                $table->text('remark')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('approval_comment')->nullable();
                $table->timestamps();

                $table->index('return_no');
                $table->index('supplier_id');
                $table->index('status');
                $table->index('return_date');
            });
        }

        if (! Schema::hasTable('purchase_return_items')) {
            Schema::create('purchase_return_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('return_id');
                $table->unsignedBigInteger('product_id');
                $table->string('product_code', 50)->nullable();
                $table->string('product_name', 200)->nullable();
                $table->string('spec', 100)->nullable();
                $table->string('unit', 20)->nullable();
                $table->integer('original_qty')->default(0)->comment('原入库数量');
                $table->integer('returned_qty')->default(0)->comment('历史已退数量');
                $table->integer('return_qty')->default(0)->comment('本次退货数量');
                $table->decimal('return_price', 10, 2)->default(0);
                $table->decimal('return_amount', 14, 2)->default(0);
                $table->timestamps();

                $table->index('return_id');
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
    }
};
