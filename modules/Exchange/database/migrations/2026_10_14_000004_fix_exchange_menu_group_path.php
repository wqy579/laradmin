<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 修正「换货管理」分组菜单的 path / component，理由同
 * BorrowReturn 的 2026_10_14_000003：分组不能自带落地页组件。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('auth_permission')
            ->where('name', 'exchange')
            ->update([
                'path' => '/business/exchange-order',
                'component' => '',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // 同上，不还原
    }
};
