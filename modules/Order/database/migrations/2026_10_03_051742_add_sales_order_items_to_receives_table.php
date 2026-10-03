<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receives', function (Blueprint $table) {
            if (! Schema::hasColumn('receives', 'sales_order_items')) {
                $table->json('sales_order_items')->nullable()->after('sales_order_id')
                    ->comment('核销订单明细：[{"order_id":1,"order_no":"SOxxx","pay_amount":100.00},...]');
            }
        });
    }

    public function down(): void
    {
        Schema::table('receives', function (Blueprint $table) {
            $table->dropColumn('sales_order_items');
        });
    }
};
