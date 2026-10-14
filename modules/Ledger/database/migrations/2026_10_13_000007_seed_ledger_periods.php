<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 预置当前会计期间（2026 年 1~12 月），状态 open，结账后置 closed。
 * 全新环境初始化用，避免月结页空空如也。
 */
return new class extends Migration
{
    public function up(): void
    {
        $year = (int) date('Y');
        for ($m = 1; $m <= 12; $m++) {
            $exists = DB::table('ledger_periods')->where('year', $year)->where('month', $m)->exists();
            if ($exists) {
                continue;
            }
            $start = sprintf('%04d-%02d-01', $year, $m);
            $end = date('Y-m-d', strtotime("last day of $year-$m"));
            DB::table('ledger_periods')->insert([
                'year' => $year,
                'month' => $m,
                'start_date' => $start,
                'end_date' => $end,
                'status' => 'open',
                'voucher_count' => 0,
                'total_debit' => 0,
                'total_credit' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // 不清理：期间是业务数据，down 只保证可回滚迁移本身
    }
};
