<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->integer('qty_large')->default(0)->after('quantity');
            $table->integer('qty_medium')->default(0)->after('qty_large');
            $table->integer('qty_small')->default(0)->after('qty_medium');
            $table->decimal('price_large', 10, 2)->default(0)->after('price');
            $table->decimal('price_medium', 10, 2)->default(0)->after('price_large');
            $table->decimal('price_small', 10, 2)->default(0)->after('price_medium');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn(['qty_large', 'qty_medium', 'qty_small', 'price_large', 'price_medium', 'price_small']);
        });
    }
};
