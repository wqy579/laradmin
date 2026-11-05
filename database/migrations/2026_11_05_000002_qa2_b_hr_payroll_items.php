<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QA2-C01 工资单补 4 个工资项目 + 「工资报表 / 导入」权限节点。
 *
 * 应发净额口径随之扩展（原为 基本+提成+绩效−扣项）：
 *   净额 = 基本 + 工龄 + 提成 + 绩效 + 加班 + 调整 + 报销 − 扣项
 * 报销金额计入应发：与工资同笔发放是通行做法，单列出来是为了能对账报销台账。
 * 存量单据这 4 项默认 0，净额不变，不会出现回填后金额漂移。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_payrolls') && ! Schema::hasColumn('hr_payrolls', 'seniority_amount')) {
            Schema::table('hr_payrolls', function (Blueprint $table) {
                $table->decimal('seniority_amount', 12, 2)->default(0)->after('base_amount')->comment('工龄工资');
                $table->decimal('overtime_amount', 12, 2)->default(0)->after('performance_amount')->comment('加班工资');
                $table->decimal('adjustment_amount', 12, 2)->default(0)->after('overtime_amount')->comment('调整工资（±）');
                $table->decimal('reimbursement_amount', 12, 2)->default(0)->after('adjustment_amount')->comment('报销金额');
            });
        }

        $this->registerPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_payrolls') && Schema::hasColumn('hr_payrolls', 'seniority_amount')) {
            Schema::table('hr_payrolls', function (Blueprint $table) {
                $table->dropColumn(['seniority_amount', 'overtime_amount', 'adjustment_amount', 'reimbursement_amount']);
            });
        }

        $names = [
            'hr.payroll.report', 'hr:payroll:report',
            'hr.payroll.import', 'hr:payroll:import',
        ];
        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }

    private function registerPermissions(): void
    {
        $parentId = DB::table('auth_permission')->where('name', 'hr.payroll')->value('id');
        if (! $parentId) {
            return;
        }

        $buttons = [
            ['name' => 'hr.payroll.report', 'alias' => 'hr:payroll:report', 'title' => '查看工资报表', 'sort' => 6],
            ['name' => 'hr.payroll.import', 'alias' => 'hr:payroll:import', 'title' => '导入工资单', 'sort' => 7],
        ];

        $idMap = [];
        foreach ($buttons as $btn) {
            foreach (array_filter([$btn['name'], $btn['alias']]) as $name) {
                if ($exists = DB::table('auth_permission')->where('name', $name)->first()) {
                    $idMap[] = $exists->id;

                    continue;
                }
                $idMap[] = DB::table('auth_permission')->insertGetId([
                    'title' => $btn['title'],
                    'name' => $name,
                    'type' => 'button',
                    'parent_id' => $parentId,
                    'path' => $name,
                    'component' => null,
                    'meta' => null,
                    'sort' => $btn['sort'],
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $superRoleId = DB::table('auth_role')->where('code', 'super_admin')->value('id');
        if (! $superRoleId) {
            return;
        }
        foreach ($idMap as $permissionId) {
            $granted = DB::table('auth_role_permission')
                ->where('role_id', $superRoleId)
                ->where('permission_id', $permissionId)
                ->exists();
            if (! $granted) {
                DB::table('auth_role_permission')->insert([
                    'role_id' => $superRoleId,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
