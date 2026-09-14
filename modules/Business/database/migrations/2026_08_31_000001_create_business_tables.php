<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 单位
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 20)->unique()->comment('单位名称');
            $table->integer('sort_order')->default(0)->comment('排序');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
        });

        // 产品分类
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable()->comment('父级分类ID');
            $table->string('name', 100)->comment('分类/品牌名称');
            $table->integer('sort_order')->default(0)->comment('排序');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            // 遗留列：2026_09_14_000001 会删除。必须保留以让历史迁移链可重放。
            $table->integer('product_count')->default(0)->comment('商品数量');
            $table->timestamps();
            $table->index('parent_id');
        });

        // 产品
        // 注意：category_id 是 2026-08-31 的原始列，后续 2026_09_03 追加
        // main_category_id / sub_category_id，再由 2026_09_14_000001 删除。
        // 不能在本处提前移除——否则 2026_09_03 的 ->after('category_id') 在全新
        // 环境会报 1054 Unknown column，导致 migrate:fresh 中断。
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200)->comment('产品名称');
            // 遗留列（外键），由 2026_09_14_000001 删除；保留以支撑历史迁移链
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('spec', 100)->nullable()->comment('规格');
            $table->string('code', 50)->nullable()->index()->comment('产品编码');
            $table->string('barcode_large', 50)->nullable()->comment('大码条码');
            $table->string('barcode_medium', 50)->nullable()->comment('中码条码');
            $table->string('barcode_small', 50)->nullable()->comment('小码条码');
            $table->string('barcode_medium_unit', 20)->nullable()->comment('中码单位');
            $table->string('unit_conversion', 50)->nullable()->comment('换算关系');
            $table->decimal('unit_conversion_medium', 10, 2)->nullable()->comment('换算系数');
            $table->decimal('price_large', 10, 2)->default(0)->comment('大码售价');
            $table->decimal('price_small', 10, 2)->default(0)->comment('小码售价');
            $table->decimal('price_medium', 10, 2)->nullable()->comment('中码售价');
            $table->decimal('cost_price_large', 10, 2)->default(0)->comment('大码成本价');
            $table->decimal('cost_price_small', 10, 2)->nullable()->comment('小码成本价');
            $table->decimal('stock_qty', 10, 3)->default(0)->comment('库存数量');
            $table->decimal('stock_amount', 14, 2)->nullable()->comment('库存金额');
            $table->decimal('cost_price', 10, 2)->nullable()->comment('综合成本价');
            $table->string('price_unit', 20)->nullable()->comment('大码单位');
            $table->string('price_unit_small', 20)->nullable()->comment('小码单位');
            $table->integer('shelf_life_days')->nullable()->comment('保质期天数');
            $table->boolean('is_online')->default(false)->comment('是否上架');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->string('image', 500)->nullable()->comment('图片');
            $table->string('external_id', 50)->nullable()->comment('外部ID');
            $table->text('remark')->nullable()->comment('备注');
            $table->timestamps();
            $table->index('barcode_large');
        });
        // 仓库
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()->comment('仓库编码');
            $table->string('name', 100)->comment('仓库名称');
            $table->string('address', 255)->nullable()->comment('地址');
            $table->string('contact', 50)->nullable()->comment('联系人');
            $table->string('phone', 20)->nullable()->comment('电话');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
        });

        // 路线（必须先于 customers 创建：customers.route_id 外键指向本表）
        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique()->comment('路线编码');
            $table->string('name', 100)->comment('路线名称');
            $table->string('area', 100)->nullable()->comment('区域');
            $table->foreignId('employee_id')->nullable()->constrained('auth_user')->nullOnDelete();
            $table->integer('sort_order')->default(0)->comment('排序');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->text('remark')->nullable()->comment('备注');
            $table->timestamps();
        });

        // 客户
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 50)->nullable()->comment('外部ID');
            $table->string('code', 20)->nullable()->index()->comment('客户编码');
            $table->string('name', 200)->comment('客户名称');
            $table->string('category', 50)->nullable()->comment('客户分类');
            $table->string('contact', 50)->nullable()->comment('联系人');
            $table->string('phone', 20)->nullable()->comment('电话');
            $table->string('address', 500)->nullable()->comment('地址');
            $table->string('route', 100)->nullable()->comment('路线');
            $table->decimal('credit_limit', 12, 2)->default(0)->comment('信用额度');
            $table->decimal('balance', 12, 2)->default(0)->comment('欠款余额');
            $table->integer('level')->default(1)->comment('等级');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->string('image', 500)->nullable()->comment('头像');
            $table->decimal('latitude', 10, 7)->nullable()->comment('纬度');
            $table->decimal('longitude', 10, 7)->nullable()->comment('经度');
            $table->string('mall_status', 20)->nullable()->comment('商城状态');
            $table->boolean('is_located')->default(false)->comment('是否定位');
            $table->foreignId('route_id')->nullable()->constrained('routes')->nullOnDelete();
            $table->text('remark')->nullable()->comment('备注');
            $table->timestamps();
        });

        // 供应商
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->nullable()->index()->comment('供应商编码');
            $table->string('name', 200)->comment('供应商名称');
            $table->string('contact', 50)->nullable()->comment('联系人');
            $table->string('phone', 20)->nullable()->comment('电话');
            $table->string('address', 500)->nullable()->comment('地址');
            $table->string('bank_name', 100)->nullable()->comment('开户行');
            $table->string('bank_account', 50)->nullable()->comment('账号');
            $table->decimal('balance', 12, 2)->default(0)->comment('余额');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->text('remark')->nullable()->comment('备注');
            $table->string('external_id', 50)->nullable()->comment('外部ID');
            $table->timestamps();
        });

        // 车辆
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('plate_no', 20)->unique()->comment('车牌号');
            $table->string('driver_name', 50)->comment('司机姓名');
            $table->string('driver_phone', 20)->nullable()->comment('司机电话');
            $table->string('vehicle_type', 50)->nullable()->comment('车辆类型');
            $table->decimal('load_capacity', 10, 2)->nullable()->comment('载重');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->text('remark')->nullable()->comment('备注');
            $table->timestamps();
        });

        // 路线客户
        Schema::create('route_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->integer('visit_order')->default(0)->comment('拜访顺序');
            $table->integer('visit_frequency')->default(1)->comment('拜访频率');
            $table->timestamps();
            $table->unique(['route_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_customers');
        Schema::dropIfExists('routes');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('units');
    }
};
