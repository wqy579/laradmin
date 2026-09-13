<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liankai_stock_change_logs', function (Blueprint $table) {
            $table->id();
            $table->string('warehouse', 100)->comment('仓库名称');
            $table->string('product_name', 200)->comment('商品名称');
            $table->string('barcode', 50)->comment('条码');
            $table->string('change_type', 20)->comment('变动类型: in=入库, out=出库, adjust=调整, freeze=冻结, unfreeze=解冻');
            $table->integer('change_qty')->default(0)->comment('变动数量');
            $table->integer('before_qty')->default(0)->comment('变动前数量');
            $table->integer('after_qty')->default(0)->comment('变动后数量');
            $table->date('check_date')->comment('核对日期');
            $table->string('reference', 100)->nullable()->comment('关联单据号');
            $table->text('remark')->nullable()->comment('备注');
            $table->timestamps();
            
            $table->index('warehouse');
            $table->index('barcode');
            $table->index('check_date');
            $table->index('change_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liankai_stock_change_logs');
    }
};
