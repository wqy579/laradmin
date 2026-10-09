<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 菜单更名：换货汇总 → 换货查询，对齐设计方案的菜单结构
 * （库存管理 → 换货管理 → 换货单 / 换货查询）。
 *
 * 幂等：按 name 定位后 UPDATE，重复执行结果一致。
 * 只改显示名，不动 path / component，页面与路由无需调整。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('auth_permission')
            ->where('name', 'exchange.summary')
            ->update(['title' => '换货查询', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('auth_permission')
            ->where('name', 'exchange.summary')
            ->update(['title' => '换货汇总', 'updated_at' => now()]);
    }
};
