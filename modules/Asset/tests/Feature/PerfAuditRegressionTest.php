<?php

namespace Tests\Asset\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Asset\Models\AssetSale;
use Tests\TestCase;

/**
 * 性能与健壮性审计回归（PERF-AUDIT-A）
 *
 * 覆盖本次修复：
 *  - asset_purchase.department_id/user_id/status、asset_card.department_id、
 *    asset_depreciation.department_id 高频筛选列索引已建；
 *  - asset_sale.sale_no 由普通索引提升为唯一索引：并发重复单号由静默入库
 *    转为抛异常回滚（与 purchase_no/asset_no 唯一模式对齐）。
 */
class PerfAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_perf_indexes_are_applied(): void
    {
        $this->assertIndex('asset_purchase', ['department_id']);
        $this->assertIndex('asset_purchase', ['user_id']);
        $this->assertIndex('asset_purchase', ['status']);
        $this->assertIndex('asset_card', ['department_id']);
        $this->assertIndex('asset_depreciation', ['department_id']);
    }

    public function test_sale_no_is_unique(): void
    {
        // 唯一索引应存在（提升后），且普通 index 已被移除（避免同列双索引）
        $this->assertIndex('asset_sale', ['sale_no'], true);
    }

    public function test_duplicate_sale_no_rejected(): void
    {
        $base = [
            'sale_no' => 'BM-202610-0001',
            'asset_card_id' => 1,
            'asset_no' => 'ZC-0001',
            'asset_name' => '注塑机',
            'sale_date' => '2026-10-20',
            'sale_amount' => 1000,
            'net_value' => 800,
            'gain_loss' => 200,
            'buyer' => '买方',
        ];

        AssetSale::create($base);

        // 同 sale_no 二次插入应触发唯一约束（sqlite 抛 QueryException）
        $this->expectException(QueryException::class);
        AssetSale::create($base);
    }

    private function assertIndex(string $table, array $columns, bool $unique = false): void
    {
        $this->assertTrue(
            Schema::hasIndex($table, $columns),
            "表 {$table} 应存在索引 ".implode(',', $columns)
        );
        if ($unique) {
            // 找到该列所在索引并断言其为唯一
            foreach (Schema::getIndexes($table) as $idx) {
                if ($idx['columns'] === $columns) {
                    $this->assertTrue($idx['unique'], "表 {$table} 的 ".implode(',', $columns).' 应为唯一索引');

                    return;
                }
            }
            $this->fail("表 {$table} 未找到 ".implode(',', $columns).' 索引');
        }
    }
}
