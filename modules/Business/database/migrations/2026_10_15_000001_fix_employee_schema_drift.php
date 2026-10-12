<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 修复生产库 employees 表 schema drift（列名与缺列）。
 *
 * 背景：create_employee_tables（2026_09_01）建表用 code / gender / salesman_code /
 * wechat_bound / base_salary / commission_rate / role / remark，但生产库的 employees
 * 表实际只有 id / user_id / name / phone / employee_code / department / position /
 * id_card / hire_date / is_active / timestamps——列名被改成了 employee_code，且缺了
 * 上面 7 列。这个 drift 的来源不明（仓库里从未有过 employee_code 建表语句，
 * 也没有 renameColumn 迁移），但后果很明确：
 *
 *   1. 业务员报表 5 个维度全 500——SalesmanReportController 用 e.code 分组和取值，
 *      列不存在直接 SQLSTATE[42S22] Unknown column 'e.code'。
 *   2. 员工创建/更新 500——EmployeeController::store 往 code 列写入，列不存在。
 *   3. 拜访模块 500——VisitLogController 也读 employees.code。
 *
 * 方向选择：对齐生产 schema 到仓库规范（改数据层），而不是改代码去迁就 drift。
 * 依据是 code 在仓库里是一致口径——建表迁移、Employee 模型 $fillable、
 * EmployeeController 的 validate/where、VisitLogController 的 pluck、
 * 前端员工表单都用 code；employee_code 只在 VisitLogController 里作为
 * COALESCE(e.code, u.username) as employee_code 的响应别名出现，不是 DB 列读取。
 * 改代码去适配 employee_code 会连带改 4 个控制器 + 前端表单 + 模型，
 * 而改 schema 只跑一条迁移。
 *
 * 幂等设计：每步都先判 hasColumn / hasIndex。全新库（migrate:fresh）天然有 code
 * 和全部列，整条迁移是 no-op；只有 drift 的生产库才真正执行 rename + add。
 * 因此这条迁移同时覆盖「全新环境」和「存量 drift 环境」两条路径。
 */
return new class extends Migration
{
    // 生产库 drift 出来的列名 → 仓库规范列名
    private const LEGACY_CODE_COLUMN = 'employee_code';

    private const CANONICAL_CODE_COLUMN = 'code';

    // drift 生产库缺的列，定义与 create_employee_tables 保持一字一致
    private const MISSING_COLUMNS = ['gender', 'salesman_code', 'wechat_bound', 'base_salary', 'commission_rate', 'role', 'remark'];

    public function up(): void
    {
        // ── 步骤 1：employee_code → code ──
        // 先删掉 drift 库上的旧索引（非唯一，名字还带着 employee_code），
        // 否则 rename 之后会留下一个名字误导的非唯一索引挂在 code 上。
        if (Schema::hasColumn('employees', self::LEGACY_CODE_COLUMN)
            && ! Schema::hasColumn('employees', self::CANONICAL_CODE_COLUMN)) {

            if (Schema::hasIndex('employees', 'employees_employee_code_index')) {
                Schema::table('employees', function (Blueprint $table) {
                    $table->dropIndex('employees_employee_code_index');
                });
            }

            Schema::table('employees', function (Blueprint $table) {
                $table->renameColumn(self::LEGACY_CODE_COLUMN, self::CANONICAL_CODE_COLUMN);
            });
        }

        // ── 步骤 2：补缺失列 ──
        // 逐列加，避免一次 Schema::table 里既有 add 又有 drop 时的顺序问题；
        // 也便于全新库上整段跳过。
        foreach (self::MISSING_COLUMNS as $column) {
            if (Schema::hasColumn('employees', $column)) {
                continue;
            }

            Schema::table('employees', function (Blueprint $table) use ($column) {
                match ($column) {
                    'gender' => $table->string('gender', 10)->nullable()->after('name'),
                    'salesman_code' => $table->string('salesman_code', 30)->nullable()->after('department'),
                    'wechat_bound' => $table->boolean('wechat_bound')->default(false)->after('phone'),
                    'base_salary' => $table->decimal('base_salary', 10, 2)->default(0)->after('hire_date'),
                    'commission_rate' => $table->decimal('commission_rate', 5, 2)->default(0)->after('base_salary'),
                    'role' => $table->string('role', 30)->default('staff')->after('user_id'),
                    'remark' => $table->text('remark')->nullable()->after('is_active'),
                    default => throw new RuntimeException("unknown column: {$column}"),
                };
            });
        }

        // ── 步骤 3：补 code 唯一索引 ──
        // 建表迁移用的是 ->unique()，Laravel 生成的索引名是 employees_code_unique。
        // 全新库已有该索引，跳过；drift 库 rename 后没有唯一索引，补上。
        // 这一步必须在 rename 之后——rename 前 code 列还不存在。
        if (! Schema::hasIndex('employees', 'employees_code_unique')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->unique(self::CANONICAL_CODE_COLUMN, 'employees_code_unique');
            });
        }
    }

    public function down(): void
    {
        // 反向操作：删索引、删新增列、把 code 改回 employee_code。
        // 仅在 drift 库上才需要真正执行；全新库上 down 同样幂等。
        //
        // 索引必须最先摘掉——SQLite 不允许 drop column 时仍有索引引用该列
        // （employees_role_index 引用 role，不先删索引 dropColumn('role') 直接报错）。
        // MySQL 无此限制，但统一先删索引在两边都安全。
        foreach (['employees_code_unique', 'employees_code_index', 'employees_role_index'] as $index) {
            if (Schema::hasIndex('employees', $index)) {
                Schema::table('employees', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index);
                });
            }
        }

        foreach (array_reverse(self::MISSING_COLUMNS) as $column) {
            if (! Schema::hasColumn('employees', $column)) {
                continue;
            }

            Schema::table('employees', function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }

        if (Schema::hasColumn('employees', self::CANONICAL_CODE_COLUMN)
            && ! Schema::hasColumn('employees', self::LEGACY_CODE_COLUMN)) {

            Schema::table('employees', function (Blueprint $table) {
                $table->renameColumn(self::CANONICAL_CODE_COLUMN, self::LEGACY_CODE_COLUMN);
            });
        }
    }
};
