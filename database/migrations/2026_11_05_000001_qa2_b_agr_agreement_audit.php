<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QA2-F04 协议档案补齐返利次数与审核口径。
 *
 * 剩余金额 = amount - redeemed_amount（即既有 pending_amount）、剩余次数 = total_times - used_times
 * 两者都是派生值，一律不落库，避免与源字段漂移。
 * 检查状态与审核状态必须落库：它们是人工动作的留痕，无法从其他字段推出。
 *
 * 存量协议历史无从追溯，审核状态一律回填 approved——回填成「待审核」会把
 * 一批已生效的在册协议集体变成未审核，反而不可用。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agr_agreements') && ! Schema::hasColumn('agr_agreements', 'total_times')) {
            Schema::table('agr_agreements', function (Blueprint $table) {
                $table->unsignedInteger('total_times')->default(0)->after('amount')->comment('协议次数（返利次数），0 表示不按次数管控');
                $table->unsignedInteger('used_times')->default(0)->after('total_times')->comment('已返次数');
                $table->string('check_status', 20)->default('pending')->after('status')->comment('检查状态 pending待检查/checked已检查');
                $table->string('audit_status', 20)->default('pending')->after('check_status')->comment('审核状态 pending待审核/approved已审核/rejected已驳回');
                $table->string('auditor_name', 50)->nullable()->after('audit_status')->comment('审核人');
                $table->timestamp('audited_at')->nullable()->after('auditor_name')->comment('审核时间');
                $table->string('audit_remark', 255)->nullable()->after('audited_at')->comment('审核意见');
                $table->index('audit_status');
            });
        }

        // 此刻表里每一行都是加列前的存量行，全量回填；加列后新建的协议走默认 pending
        DB::table('agr_agreements')->update([
            'audit_status' => 'approved',
            'auditor_name' => '历史数据',
            'audited_at' => now(),
        ]);

        $this->registerAuditPermission();
    }

    public function down(): void
    {
        if (Schema::hasTable('agr_agreements') && Schema::hasColumn('agr_agreements', 'total_times')) {
            Schema::table('agr_agreements', function (Blueprint $table) {
                $table->dropIndex(['audit_status']);
                $table->dropColumn([
                    'total_times', 'used_times', 'check_status',
                    'audit_status', 'auditor_name', 'audited_at', 'audit_remark',
                ]);
            });
        }

        $names = ['agr.list.audit', 'agr:list:audit'];
        $ids = DB::table('auth_permission')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('auth_role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('auth_permission')->whereIn('id', $ids)->delete();
        }
    }

    /** 「审核」是新增动作，单挂一个权限节点，授权口径与 register_agr_menu 一致 */
    private function registerAuditPermission(): void
    {
        $parentId = DB::table('auth_permission')->where('name', 'agr.list')->value('id');
        if (! $parentId) {
            return;
        }

        $idMap = [];
        foreach ([['name' => 'agr.list.audit', 'sort' => 7], ['name' => 'agr:list:audit', 'sort' => 7]] as $btn) {
            if ($exists = DB::table('auth_permission')->where('name', $btn['name'])->first()) {
                $idMap[] = $exists->id;

                continue;
            }
            $idMap[] = DB::table('auth_permission')->insertGetId([
                'title' => '审核协议',
                'name' => $btn['name'],
                'type' => 'button',
                'parent_id' => $parentId,
                'path' => $btn['name'],
                'component' => null,
                'meta' => null,
                'sort' => $btn['sort'],
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
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
