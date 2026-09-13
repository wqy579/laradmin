<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 确保external_id字段存在
        if (!Schema::hasColumn('products', 'external_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('external_id', 50)->nullable()->after('image')->comment('外部ID');
            });
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('external_id');
        });
    }
};
