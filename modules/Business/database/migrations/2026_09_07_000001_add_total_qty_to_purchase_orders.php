<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // create_transaction_tables 建表时已包含 total_qty，幂等保护避免重复加列
        if (Schema::hasColumn('purchase_orders', 'total_qty')) {
            return;
        }
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->integer('total_qty')->default(0)->after('total_amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('purchase_orders', 'total_qty')) {
            return;
        }
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('total_qty');
        });
    }
};
