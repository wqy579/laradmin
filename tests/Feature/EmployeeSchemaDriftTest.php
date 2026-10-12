<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 生产库 employees 表 schema drift 的修复迁移验证。
 *
 * 事故：生产 employees 表列名被改成了 employee_code、且缺 7 个列，导致
 *   - 业务员报表 5 个维度全 500（SQLSTATE[42S22] Unknown column 'e.code'）
 *   - 员工创建/更新 500（写入不存在的 code 列）
 *   - 拜访模块 500（VisitLogController 读 employees.code）
 *
 * 修复迁移 2026_10_15_000001_fix_employee_schema_drift 是幂等的：
 * 全新库天然有 code 和全部列，是 no-op；只有 drift 库才执行 rename + add。
 * 因此两条路径都要测，且必须分别验证——只测全新库会漏掉真正的修复路径。
 */
class EmployeeSchemaDriftTest extends TestCase
{
    private const MISSING = ['gender', 'salesman_code', 'wechat_bound', 'base_salary', 'commission_rate', 'role', 'remark'];

    public function test_fresh_db_migration_is_noop(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('本用例在测试进程内 migrate:fresh，需 SQLite :memory: 隔离；当前驱动 '.DB::getDriverName());
        }

        $this->assertSame(0, Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]));

        $columns = Schema::getColumnListing('employees');

        // 全新库：规范列名 code 存在，drift 列名 employee_code 不存在
        $this->assertContains('code', $columns, '全新库应有规范列 code');
        $this->assertNotContains('employee_code', $columns, '全新库不应有 drift 列 employee_code');

        // 全部 7 个列齐全
        foreach (self::MISSING as $column) {
            $this->assertContains($column, $columns, "全新库缺少列 {$column}");
        }

        // 唯一索引在位
        $this->assertTrue(Schema::hasIndex('employees', 'employees_code_unique'), '全新库应已有 code 唯一索引');
    }

    public function test_migration_repairs_drifted_table(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('本用例在测试进程内 migrate:fresh，需 SQLite :memory: 隔离；当前驱动 '.DB::getDriverName());
        }

        // 1) 从零建库
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]));

        // 2) 人为制造与生产一致的 drift：code → employee_code，删掉 7 个列和唯一索引
        $this->simulateDrift();

        // 此刻 code 已不存在，正是生产上业务员报表 500 的状态
        $this->assertNotContains('code', Schema::getColumnListing('employees'), 'drift 后 code 应不存在');

        // 3) 抹掉本迁移的执行记录，让它重新跑一遍
        DB::table('migrations')->where('migration', '2026_10_15_000001_fix_employee_schema_drift')->delete();

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]), '修复迁移执行失败：'.Artisan::output());

        // 4) 断言修复结果
        $columns = Schema::getColumnListing('employees');

        $this->assertContains('code', $columns, '修复后应恢复规范列 code');
        $this->assertNotContains('employee_code', $columns, '修复后不应残留 drift 列 employee_code');

        foreach (self::MISSING as $column) {
            $this->assertContains($column, $columns, "修复后缺少列 {$column}");
        }

        $this->assertTrue(Schema::hasIndex('employees', 'employees_code_unique'), '修复后应补上 code 唯一索引');
    }

    public function test_drifted_table_is_idempotent(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('本用例在测试进程内 migrate:fresh，需 SQLite :memory: 隔离；当前驱动 '.DB::getDriverName());
        }

        $this->assertSame(0, Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]));
        $this->simulateDrift();

        // 连续跑三次，结果必须稳定不变
        $baseline = Schema::getColumnListing('employees');

        foreach ([1, 2, 3] as $i) {
            $this->assertSame(
                0,
                Artisan::call('migrate', ['--force' => true]),
                "第 {$i} 次 migrate 失败（迁移可能不幂等）"
            );
            $this->assertSame($baseline, Schema::getColumnListing('employees'), "第 {$i} 次 migrate 后 schema 漂移了");
        }
    }

    /**
     * 复现生产 drift 形态：employee_code 代替 code，缺 7 列，无唯一索引。
     * 用 Schema::table 逐列改，避免一次性 rebuild 带来的方言差异。
     */
    private function simulateDrift(): void
    {
        // SQLite 的 drop column 不能与残留索引共存，必须先摘掉建表迁移加的索引
        foreach (['employees_code_unique', 'employees_code_index', 'employees_role_index'] as $index) {
            if (Schema::hasIndex('employees', $index)) {
                Schema::table('employees', function ($table) use ($index) {
                    $table->dropIndex($index);
                });
            }
        }

        Schema::table('employees', function ($table) {
            $table->renameColumn('code', 'employee_code');
        });

        foreach (self::MISSING as $column) {
            if (! Schema::hasColumn('employees', $column)) {
                continue;
            }

            Schema::table('employees', function ($table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }
}
