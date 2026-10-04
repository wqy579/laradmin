<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商品拆分单：把一个父件(被拆商品)拆成多个子件。
 * 与组装单对称，方向相反：父件出库、子件入库并按售价比例分摊成本。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('split_orders')) {
            Schema::create('split_orders', function (Blueprint $table) {
                $table->id();
                $table->string('split_no', 30)->unique()->comment('拆分单号CF+Ymd+6位');
                $table->unsignedBigInteger('parent_product_id')->comment('被拆商品ID');
                $table->string('parent_product_name', 200)->nullable()->comment('被拆商品名冗余');
                $table->string('parent_product_code', 50)->nullable()->comment('被拆商品编码冗余');
                $table->unsignedBigInteger('warehouse_id')->nullable()->comment('仓库ID');
                $table->integer('quantity')->default(1)->comment('拆分数量(父件最小单位)');
                $table->decimal('total_cost', 14, 2)->default(0)->comment('父件出库总成本');
                $table->string('status', 20)->default('draft')->comment('状态:draft/pending/approved/cancelled');
                $table->unsignedBigInteger('salesman_id')->nullable()->comment('业务员ID');
                $table->date('split_date')->comment('拆分日期');
                $table->text('remark')->nullable()->comment('备注');
                $table->unsignedBigInteger('created_by')->nullable()->comment('制单人ID');
                $table->unsignedBigInteger('approved_by')->nullable()->comment('审核人ID');
                $table->timestamp('approved_at')->nullable()->comment('审核时间');
                $table->text('approval_comment')->nullable()->comment('审核意见');
                $table->timestamps();

                $table->index('split_no');
                $table->index('parent_product_id');
                $table->index('warehouse_id');
                $table->index('status');
                $table->index('split_date');
            });
        }

        if (! Schema::hasTable('split_order_items')) {
            Schema::create('split_order_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('split_order_id')->comment('拆分单ID');
                $table->unsignedBigInteger('product_id')->comment('子件商品ID');
                $table->string('product_code', 50)->nullable()->comment('子件编码冗余');
                $table->string('product_name', 200)->nullable()->comment('子件名冗余');
                $table->string('spec', 100)->nullable()->comment('规格冗余');
                $table->string('unit', 20)->nullable()->comment('单位冗余');
                $table->integer('split_qty')->default(1)->comment('拆分数量(一件父件拆出多少子件)');
                $table->integer('split_total')->default(0)->comment('拆出总数=split_qty×quantity');
                $table->decimal('unit_cost', 10, 2)->default(0)->comment('子件单位成本(分摊后)');
                $table->decimal('total_cost', 14, 2)->default(0)->comment('子件总成本=split_total×unit_cost');
                $table->timestamps();

                $table->index('split_order_id');
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('split_order_items');
        Schema::dropIfExists('split_orders');
    }
};
