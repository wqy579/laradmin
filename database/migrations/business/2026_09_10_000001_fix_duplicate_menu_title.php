<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // inventory.query 应该叫"库存查询"，不是"库存核对"
        DB::table('auth_permission')
            ->where('name', 'inventory.query')
            ->update(['title' => '库存查询']);
    }

    public function down(): void
    {
        DB::table('auth_permission')
            ->where('name', 'inventory.query')
            ->update(['title' => '库存核对']);
    }
};
