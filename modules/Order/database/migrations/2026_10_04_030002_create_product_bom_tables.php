<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOM 物料清单模板：提前定义某父件组装/拆分时所需的子件与用量。
 * 关系表存储（主表 + 明细表），一个商品可有 assembly 和 split 两套 BOM。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_bom')) {
            Schema::create('product_bom', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->comment('父件商品ID');
                $table->string('type', 10)->comment('类型:assembly/split');
                $table->string('name', 100)->nullable()->comment('BOM名称');
                $table->text('remark')->nullable()->comment('备注');
                $table->timestamps();

                $table->unique(['product_id', 'type']);
                $table->index('product_id');
            });
        }

        if (! Schema::hasTable('product_bom_items')) {
            Schema::create('product_bom_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('bom_id')->comment('BOM主表ID');
                $table->unsignedBigInteger('product_id')->comment('子件商品ID');
                $table->string('product_code', 50)->nullable()->comment('子件编码冗余');
                $table->string('product_name', 200)->nullable()->comment('子件名冗余');
                $table->decimal('unit_usage', 10, 3)->default(1)->comment('单位用量(可存小数,应用时取整)');
                $table->decimal('unit_cost', 10, 2)->default(0)->comment('预设单位成本');
                $table->text('remark')->nullable()->comment('备注');
                $table->timestamps();

                $table->index('bom_id');
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_bom_items');
        Schema::dropIfExists('product_bom');
    }
};
