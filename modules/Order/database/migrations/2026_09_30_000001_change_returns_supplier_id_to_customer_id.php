<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 退货退的是客户的货，不是供应商的货；supplier_id → customer_id
        Schema::table('returns', function (Blueprint $table) {
            if (Schema::hasColumn('returns', 'supplier_id')) {
                // 先删外键（名字可能不同，容错）
                try {
                    $table->dropForeign(['supplier_id']);
                } catch (Throwable $e) {
                }
                $table->renameColumn('supplier_id', 'customer_id');
            }
        });
        if (! Schema::hasColumn('returns', 'customer_id')) {
            Schema::table('returns', function (Blueprint $table) {
                $table->foreignId('customer_id')->after('order_no')->constrained('customers')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            if (Schema::hasColumn('returns', 'customer_id')) {
                try {
                    $table->dropForeign(['customer_id']);
                } catch (Throwable $e) {
                }
                $table->renameColumn('customer_id', 'supplier_id');
            }
        });
    }
};
