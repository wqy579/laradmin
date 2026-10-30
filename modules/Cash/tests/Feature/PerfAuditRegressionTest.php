<?php

namespace Tests\Cash\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 性能与健壮性审计回归（PERF-AUDIT-A）
 *
 * 覆盖本次修复：cash_card_txn.salesman_id、cash_handover.salesman_id/account_id
 * 高频筛选列索引已建。
 */
class PerfAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_perf_indexes_are_applied(): void
    {
        $this->assertIndex('cash_card_txn', ['salesman_id']);
        $this->assertIndex('cash_handover', ['salesman_id']);
        $this->assertIndex('cash_handover', ['account_id']);
    }

    private function assertIndex(string $table, array $columns): void
    {
        $this->assertTrue(
            Schema::hasIndex($table, $columns),
            "表 {$table} 应存在索引 ".implode(',', $columns)
        );
    }
}
