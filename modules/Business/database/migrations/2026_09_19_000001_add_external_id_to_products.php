<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 给 products 补 external_id 索引（列建表时已存在）。
 *
 * products.external_id 在 create_business_tables(2026_08_31) 建表时已存在，
 * add_liankai_sync_to_products(2026_09_07) 还用 Schema::hasColumn 幂等兜底过。
 * 本迁移只补索引（联凯 cpid 作为同步对齐主键时按它查），幂等。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('products', 'idx_products_external_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->index('external_id', 'idx_products_external_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('products', 'idx_products_external_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('idx_products_external_id');
            });
        }
    }
};
