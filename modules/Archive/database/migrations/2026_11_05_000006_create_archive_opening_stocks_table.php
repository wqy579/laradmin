<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 开账期初库存暂存表（F6 第二批 · 第 2 步）。
 *
 * 先暂存、锁定时才回写真实 stocks：草稿态可以反复改，未锁定期不回写实时库存。
 * product_id/warehouse_id 只存 ID 不建外键——Stock 模块的商品/仓库表由别的机器负责，
 * 迁移时序上也不保证先于本表存在，与 Hr/Cash 引用外部主键的做法一致。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('archive_opening_stocks')) {
            Schema::create('archive_opening_stocks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('wizard_id')->comment('向导ID');
                $table->unsignedBigInteger('product_id')->comment('商品ID');
                $table->unsignedBigInteger('warehouse_id')->comment('仓库ID');
                $table->integer('quantity')->default(0)->comment('期初数量');
                $table->decimal('cost_price', 10, 2)->default(0)->comment('成本单价');
                $table->decimal('total_amount', 14, 2)->default(0)->comment('金额（数量×单价）');
                $table->string('source', 20)->default('manual')->comment('manual手工录入/import Excel导入');
                $table->timestamps();

                $table->unique(['wizard_id', 'product_id', 'warehouse_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_opening_stocks');
    }
};
