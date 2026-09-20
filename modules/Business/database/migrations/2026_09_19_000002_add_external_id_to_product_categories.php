<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 幂等：生产库 product_categories 可能已有 external_id 列（手动/历史途径加过），
        // create_business_tables 建表时未含此列，故 CI fresh 仍需加。
        if (! Schema::hasColumn('product_categories', 'external_id')) {
            Schema::table('product_categories', function (Blueprint $table) {
                $table->string('external_id', 50)->nullable()->after('name')->comment('连凯分类编码 qtbm');
            });
        }
        if (! Schema::hasIndex('product_categories', 'product_categories_external_id_index')) {
            Schema::table('product_categories', function (Blueprint $table) {
                $table->index('external_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('product_categories', 'product_categories_external_id_index')) {
            Schema::table('product_categories', function (Blueprint $table) {
                $table->dropIndex(['external_id']);
            });
        }
        if (Schema::hasColumn('product_categories', 'external_id')) {
            Schema::table('product_categories', function (Blueprint $table) {
                $table->dropColumn('external_id');
            });
        }
    }
};
