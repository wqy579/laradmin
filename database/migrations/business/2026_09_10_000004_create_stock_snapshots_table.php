<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 兼容已手工建表的旧环境
        if (!Schema::hasTable('stock_snapshots')) {
            Schema::create('stock_snapshots', function (Blueprint $table) {
                $table->id();
                $table->date('snapshot_date')->comment('快照日期');
                $table->unsignedBigInteger('product_id')->comment('商品ID');
                $table->unsignedBigInteger('warehouse_id')->comment('仓库ID');
                $table->decimal('quantity', 12, 2)->default(0)->comment('可用库存');
                $table->decimal('frozen_qty', 12, 2)->default(0)->comment('冻结库存');
                $table->timestamp('created_at')->nullable();
                $table->unique(['snapshot_date', 'product_id', 'warehouse_id'], 'uidx_snap');
                $table->index('snapshot_date', 'idx_date');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_snapshots');
    }
};
