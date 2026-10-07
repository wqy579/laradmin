<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 订单列表汇总栏需要显示总重量/体积，来源是商品的单件规格。
 * 均为可空字段，不回填历史数据——未维护规格的商品汇总时按 0 计。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('weight', 10, 3)->nullable()->comment('单件重量(吨)');
            $table->decimal('volume', 10, 3)->nullable()->comment('单件体积(m³)');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['weight', 'volume']);
        });
    }
};
