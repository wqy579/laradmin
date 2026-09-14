<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liankai_stock_checks', function (Blueprint $table) {
            $table->id();
            $table->string('warehouse', 100)->comment('仓库名称');
            $table->string('product_name', 200)->comment('商品名称');
            $table->string('barcode', 50)->comment('条码');
            $table->string('product_code', 50)->nullable()->comment('商品编号');
            $table->string('spec', 100)->nullable()->comment('规格型号');
            $table->date('prod_date')->nullable()->comment('生产日期');
            $table->string('yesterday_stock', 50)->nullable()->comment('昨日库存');
            $table->string('out_qty', 50)->nullable()->comment('出库数量');
            $table->string('in_qty', 50)->nullable()->comment('入库数量');
            $table->string('adjust_qty', 50)->nullable()->comment('调整数量');
            $table->string('frozen_qty', 50)->nullable()->comment('冻结库存');
            $table->string('today_stock', 50)->nullable()->comment('今日库存');
            $table->date('check_date')->comment('核对日期');
            $table->timestamps();
            
            $table->index('warehouse');
            $table->index('barcode');
            $table->index('check_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liankai_stock_checks');
    }
};
