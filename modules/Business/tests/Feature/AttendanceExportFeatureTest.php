<?php

namespace Tests\Business\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Auth\Seeders\AuthSeeder;
use Modules\Business\Models\Attendance;
use Modules\Business\Models\Employee;
use Modules\Business\Seeders\BusinessSeeder;
use Tests\TestCase;

/**
 * 考勤列表导出（B 域假按钮清除 · 任务 2）。
 *
 * 钉住三件事：
 *  1) 导出筛选与 index() 逐一对齐——员工/日期区间命中同一集合；date 为纯日期列，
 *     闭区间上界当天必须在内、次日必须在外；
 *  2) 导出是带 BOM 的 text/csv，表头与数据行齐全，含逗号字段加引号转义；
 *  3) fin:attendance:export 是服务端真拦：无 token 401、有登录但无节点 403；
 *     且节点除迁移双写外 BusinessSeeder 也必须注册（全新安装路径）。
 */
class AttendanceExportFeatureTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/admin/business/attendance';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::create([
            'code' => 'EMP'.Str::random(6),
            'name' => '李四',
            'is_active' => true,
        ]);
    }

    private function makeRow(array $attributes = []): Attendance
    {
        return Attendance::create(array_merge([
            'employee_id' => $this->employee->id,
            'date' => '2026-10-10',
            'clock_in' => '2026-10-10 09:00:00',
            'status' => 1,
            'visit_count' => 3,
        ], $attributes));
    }

    public function test_export_returns_bom_csv_following_list_filters(): void
    {
        $this->actingAsAdmin();

        // 上界当天（date 为纯日期列，闭区间必须包含当天）
        $this->makeRow([
            'date' => '2026-10-15',
            'clock_in' => '2026-10-15 09:05:00',
            'clock_out' => '2026-10-15 18:10:00',
            'remark' => '外勤,跨区',
        ]);
        // 下界当天
        $this->makeRow(['date' => '2026-10-01', 'clock_in' => '2026-10-01 09:00:00']);
        // 区间外：上界次日 / 下界前一天
        $this->makeRow(['date' => '2026-10-16', 'clock_in' => '2026-10-16 09:00:00']);
        $this->makeRow(['date' => '2026-09-30', 'clock_in' => '2026-09-30 09:00:00']);
        // 区间内但属另一员工
        $other = Employee::create(['code' => 'EMP'.Str::random(6), 'name' => '王五', 'status' => 1]);
        $this->makeRow(['employee_id' => $other->id, 'date' => '2026-10-12', 'clock_in' => '2026-10-12 09:00:00']);

        $res = $this->get(self::BASE.'/export?employee_id='.$this->employee->id
            .'&start_date=2026-10-01&end_date=2026-10-15');

        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attendance.csv', (string) $res->headers->get('Content-Disposition'));

        $body = $res->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('员工编码,员工姓名,日期,上班时间,下班时间,状态,拜访数,备注', $body);

        // 区间内本员工两行都在；含逗号备注必须整体加引号，不能把列冲开
        $this->assertStringContainsString($this->employee->code, $body);
        $this->assertStringContainsString('李四', $body);
        $this->assertStringContainsString('2026-10-15', $body);
        $this->assertStringContainsString('2026-10-01', $body);
        $this->assertStringContainsString('正常', $body);
        $this->assertStringContainsString('"外勤,跨区"', $body);

        // 区间外与他人的行不得混入
        $this->assertStringNotContainsString('2026-10-16', $body);
        $this->assertStringNotContainsString('2026-09-30', $body);
        $this->assertStringNotContainsString($other->code, $body);
        $this->assertStringNotContainsString('王五', $body);
    }

    public function test_export_route_not_swallowed_by_show_placeholder(): void
    {
        // /export 排在 /{attendance} 之前：若顺序反了，export 会被当成 id 查询 404
        $this->actingAsAdmin();
        $this->makeRow();

        $this->get(self::BASE.'/export')->assertOk();
    }

    public function test_export_rejects_unauthenticated_and_users_without_permission(): void
    {
        // 无 token：中间件第一道 401
        $this->get(self::BASE.'/export')->assertStatus(401);

        // 已登录但角色未挂 fin:attendance:export：服务端 403
        $plain = User::create([
            'username' => 'plain_'.Str::random(6),
            'password' => Hash::make('secret123'),
            'real_name' => '普通用户',
            'status' => 1,
        ]);
        $role = Role::create(['code' => 'clerk_'.Str::random(6), 'name' => '文员', 'sort' => 0, 'status' => 1]);
        $plain->roles()->attach($role->id);

        $this->resetJwtState();
        $this->withToken($this->loginToken($plain))->get(self::BASE.'/export')->assertStatus(403);
    }

    public function test_export_permission_node_registered_by_seeder(): void
    {
        // 全新安装路径：AuthSeeder（建角色）+ BusinessSeeder（菜单与按钮节点）
        $this->seed(AuthSeeder::class);
        $this->seed(BusinessSeeder::class);

        $permId = DB::table('auth_permission')->where('name', 'fin:attendance:export')->value('id');
        $this->assertNotNull($permId, 'BusinessSeeder 未注册 fin:attendance:export 节点');

        $parentId = DB::table('auth_permission')->where('name', 'visit.attendance')->value('id');
        $this->assertNotNull($parentId);
        $this->assertSame($parentId, DB::table('auth_permission')->where('name', 'fin:attendance:export')->value('parent_id'));

        $superRoleId = DB::table('auth_role')->where('code', 'super_admin')->value('id');
        $this->assertNotNull(
            DB::table('auth_role_permission')->where('role_id', $superRoleId)->where('permission_id', $permId)->value('id'),
            'fin:attendance:export 未授权给 super_admin'
        );
    }
}
