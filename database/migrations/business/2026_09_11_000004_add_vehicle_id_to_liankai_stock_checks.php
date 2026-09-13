<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 为连凯库存核对表添加 vehicle_id 字段，
     * 将连凯系统中的"车仓库"名称与 LarAdmin 的 vehicles 表关联。
     */
    public function up(): void
    {
        Schema::table('liankai_stock_checks', function (Blueprint $table) {
            $table->unsignedBigInteger('vehicle_id')->nullable()->after('warehouse');
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->nullOnDelete();
            $table->index('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::table('liankai_stock_checks', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
            $table->dropIndex(['vehicle_id']);
            $table->dropColumn('vehicle_id');
        });
    }
};
