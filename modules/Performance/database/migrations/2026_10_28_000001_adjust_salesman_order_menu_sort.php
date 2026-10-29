<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 机器 C（C7）：业务员订单汇总菜单 sort 6 与「经营历程」(report.history) 撞位，
 * 挪到空闲的 sort 10。已播种的库通过本迁移修正；新库由 Seeder /
 * 2026_10_13_000002 直接落 10，本迁移对其为无害幂等操作。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('auth_permission')
            ->where('name', 'report.salesman-order')
            ->update(['sort' => 10]);
    }

    public function down(): void
    {
        DB::table('auth_permission')
            ->where('name', 'report.salesman-order')
            ->update(['sort' => 6]);
    }
};
