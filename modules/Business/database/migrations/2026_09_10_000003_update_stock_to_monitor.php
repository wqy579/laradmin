<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 更新库存查询为库存监控
        DB::table('auth_permission')
            ->where('name', 'inventory.query')
            ->update([
                'title' => '库存监控',
                'path' => '/business/stock-monitor',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('auth_permission')
            ->where('name', 'inventory.query')
            ->update([
                'title' => '库存查询',
                'path' => '/business/stock',
            ]);
    }
};
