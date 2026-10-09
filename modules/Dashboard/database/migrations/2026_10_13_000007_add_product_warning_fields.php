<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商品库存预警字段：安全库存 / 最高库存 / 保质期。
 *
 * 智慧大屏「库存预警」需要这三个维度。products 此前缺这些列，本迁移幂等补齐。
 * Dashboard 模块读 products，属 Dashboard → Stock 单向引用（同库同事务）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'safety_stock')) {
            return;
        }
        Schema::table('products', function (Blueprint $table) {
            $table->integer('safety_stock')->default(0)->comment('安全库存')->after('is_key');
            $table->integer('max_stock')->default(0)->comment('最高库存(0=不限制)')->after('safety_stock');
            $table->date('expiry_date')->nullable()->comment('保质期至')->after('max_stock');

            $table->index('safety_stock');
            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'safety_stock')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn(['safety_stock', 'max_stock', 'expiry_date']);
            });
        }
    }
};
