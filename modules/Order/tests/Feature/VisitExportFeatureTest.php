<?php

namespace Tests\Order\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Auth\Seeders\AuthSeeder;
use Modules\Business\Seeders\BusinessSeeder;
use Modules\Order\Models\Route;
use Modules\Order\Models\VisitLog;
use Tests\TestCase;

/**
 * 拜访记录列表导出（B 域假按钮清除 · 任务 1）。
 *
 * 钉住三件事：
 *  1) 导出筛选与 index() 逐一对齐——员工/日期区间/状态命中同一集合，区间外不得混入；
 *  2) 导出是带 BOM 的 text/csv，表头与数据行齐全，含逗号字段加引号转义；
 *  3) fin:visit:export 是服务端真拦：无 token 401、有登录但无节点 403、super_admin 200；
 *     且节点除迁移双写外 BusinessSeeder 也必须注册（全新安装路径）。
 */
class VisitExportFeatureTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/admin/business/visit';

    private User $employee;

    private Route $route;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = User::create([
            'username' => 'emp_'.Str::random(6),
            'password' => Hash::make('secret123'),
            'real_name' => '张三丰',
            'status' => 1,
        ]);
        $this->route = Route::create(['code' => 'R'.Str::random(6), 'name' => '华东线', 'is_active' => true]);
    }

    private function makeLog(array $attributes = []): VisitLog
    {
        return VisitLog::create(array_merge([
            'visit_no' => 'BF'.Str::random(10),
            'employee_id' => $this->employee->id,
            'customer_id' => $this->makeCustomer()->id,
            'route_id' => $this->route->id,
            'checkin_time' => '2026-10-10 09:00:00',
            'status' => 2,
        ], $attributes));
    }

    public function test_export_returns_bom_csv_following_list_filters(): void
    {
        $this->actingAsAdmin();

        $this->makeLog([
            'visit_no' => 'BF-IN-RANGE',
            'checkin_time' => '2026-10-15 09:30:00',
            'visit_result' => '正常',
            'remark' => '带了样品,报价单',
            'checkout_time' => '2026-10-15 10:30:00',
            'visit_duration' => 60,
        ]);
        $this->makeLog(['visit_no' => 'BF-AFTER-END', 'checkin_time' => '2026-10-16 09:00:00']);
        $this->makeLog(['visit_no' => 'BF-BEFORE-START', 'checkin_time' => '2026-09-30 09:00:00']);
        $this->makeLog(['visit_no' => 'BF-PENDING', 'checkin_time' => '2026-10-12 09:00:00', 'status' => 1]);

        $res = $this->get(self::BASE.'/logs/export?employee_id='.$this->employee->id
            .'&date_start=2026-10-01&date_end=2026-10-15&status=2');

        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('visit_logs.csv', (string) $res->headers->get('Content-Disposition'));

        $body = $res->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('拜访单号,员工,客户,线路,签到时间,签退时间,时长(分),状态,拜访结果,签到地址,备注', $body);

        // 区间内 + 已拜访：在；含逗号备注必须整体加引号，不能把列冲开
        $this->assertStringContainsString('BF-IN-RANGE', $body);
        $this->assertStringContainsString('张三丰', $body);
        $this->assertStringContainsString('华东线', $body);
        $this->assertStringContainsString('已拜访', $body);
        $this->assertStringContainsString('"带了样品,报价单"', $body);

        // 区间外（上界次日 / 下界之前）与状态不符的不得混入
        $this->assertStringNotContainsString('BF-AFTER-END', $body);
        $this->assertStringNotContainsString('BF-BEFORE-START', $body);
        $this->assertStringNotContainsString('BF-PENDING', $body);
    }

    public function test_export_route_not_swallowed_by_show_placeholder(): void
    {
        // /logs/export 排在 /logs/{visitLog} 之前：若顺序反了，export 会被当成 id 查询 404
        $this->actingAsAdmin();
        $this->makeLog();

        $this->get(self::BASE.'/logs/export')->assertOk();
    }

    public function test_export_rejects_unauthenticated_and_users_without_permission(): void
    {
        // 无 token：中间件第一道 401
        $this->get(self::BASE.'/logs/export')->assertStatus(401);

        // 已登录但角色未挂 fin:visit:export：服务端 403
        $plain = User::create([
            'username' => 'plain_'.Str::random(6),
            'password' => Hash::make('secret123'),
            'real_name' => '普通用户',
            'status' => 1,
        ]);
        $role = Role::create(['code' => 'clerk_'.Str::random(6), 'name' => '文员', 'sort' => 0, 'status' => 1]);
        $plain->roles()->attach($role->id);

        $this->resetJwtState();
        $this->withToken($this->loginToken($plain))->get(self::BASE.'/logs/export')->assertStatus(403);
    }

    public function test_export_permission_node_registered_by_seeder(): void
    {
        // 全新安装路径：AuthSeeder（建角色）+ BusinessSeeder（菜单与按钮节点）
        $this->seed(AuthSeeder::class);
        $this->seed(BusinessSeeder::class);

        $permId = DB::table('auth_permission')->where('name', 'fin:visit:export')->value('id');
        $this->assertNotNull($permId, 'BusinessSeeder 未注册 fin:visit:export 节点');

        $parentId = DB::table('auth_permission')->where('name', 'visit.visit')->value('id');
        $this->assertNotNull($parentId);
        $this->assertSame($parentId, DB::table('auth_permission')->where('name', 'fin:visit:export')->value('parent_id'));

        $superRoleId = DB::table('auth_role')->where('code', 'super_admin')->value('id');
        $this->assertNotNull(
            DB::table('auth_role_permission')->where('role_id', $superRoleId)->where('permission_id', $permId)->value('id'),
            'fin:visit:export 未授权给 super_admin'
        );
    }
}
