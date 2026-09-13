<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('main_category_id')->nullable()->after('category_id')
                ->comment('主分类ID（一级分类）');
            $table->unsignedBigInteger('sub_category_id')->nullable()->after('main_category_id')
                ->comment('副分类ID（二级分类）');
            $table->index('main_category_id');
            $table->index('sub_category_id');
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->boolean('is_main')->default(false)->after('parent_id')
                ->comment('是否为主分类（一级分类）');
            $table->index('is_main');
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropIndex(['is_main']);
            $table->dropColumn('is_main');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['main_category_id', 'sub_category_id']);
            $table->dropColumn(['main_category_id', 'sub_category_id']);
        });
    }
};
