<?php

namespace Tests\Business\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * B9 一般费用单验收测试（数据源 expenses 表，走 ExpenseService）。
 *
 * 覆盖验收标准：
 *  - 列表筛选（费用类型 / 状态 / 日期区间）
 *  - 新建：自动单号 FY+Ymd、初始草稿
 *  - 审核通过：状态推进；已审单据禁止改/删/重复审核
 *  - 未授权（未登录）被拒
 *  - 列表金额合计与明细之和一致
 */
class GeneralExpenseFeatureTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/admin/business/general-expense';

    private function createExpense(array $overrides = []): int
    {
        $res = $this->postJson(self::BASE, array_merge([
            'expense_type' => '办公用品',
            'amount' => 100.00,
            'expense_date' => '2026-10-01',
            'remark' => '测试费用',
        ], $overrides))->assertOk()->assertJson(['code' => 200]);

        return (int) $res->json('data.id');
    }

    public function test_create_generates_no_and_draft_status(): void
    {
        $this->actingAsAdmin();

        $res = $this->postJson(self::BASE, [
            'expense_type' => '差旅费',
            'amount' => 1234.56,
            'expense_date' => '2026-10-05',
            'remark' => '出差高铁',
        ])->assertOk()->assertJson(['code' => 200]);

        $this->assertSame('FY'.date('Ymd'), substr($res->json('data.expense_no'), 0, 10));
        $this->assertSame(0, (int) $res->json('data.status'));
        $this->assertSame(1234.56, (float) $res->json('data.amount'));
        $this->assertSame(1, DB::table('expenses')->count());
    }

    public function test_create_requires_type_and_positive_amount(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::BASE, ['amount' => 100])->assertStatus(422);
        $this->postJson(self::BASE, ['expense_type' => '租金', 'amount' => 0])->assertStatus(422);
    }

    public function test_list_filters_by_type_status_and_date(): void
    {
        $this->actingAsAdmin();

        $this->createExpense(['expense_type' => '办公用品', 'expense_date' => '2026-10-01']);
        $this->createExpense(['expense_type' => '差旅费', 'expense_date' => '2026-10-20']);
        $approvedId = $this->createExpense(['expense_type' => '租金', 'expense_date' => '2026-11-02']);
        $this->postJson(self::BASE."/{$approvedId}/approve")->assertOk();

        $this->assertSame(1, $this->getJson(self::BASE.'?expense_type='.urlencode('差旅费'))->assertOk()->json('data.total'));
        $this->assertSame(1, $this->getJson(self::BASE.'?status=1')->assertOk()->json('data.total'));
        $this->assertSame(2, $this->getJson(self::BASE.'?status=0')->assertOk()->json('data.total'));

        $range = $this->getJson(self::BASE.'?start_date=2026-10-01&end_date=2026-10-31')->assertOk()->json('data');
        $this->assertSame(2, $range['total']);

        // 类型 + 状态组合
        $this->assertSame(0, $this->getJson(self::BASE.'?expense_type='.urlencode('租金').'&status=0')->assertOk()->json('data.total'));
    }

    public function test_approve_passes_and_locks_doc(): void
    {
        $this->actingAsAdmin();

        $id = $this->createExpense();

        $res = $this->postJson(self::BASE."/{$id}/approve")->assertOk();
        $this->assertSame(1, (int) $res->json('data.status'));
        $this->assertSame(1, (int) DB::table('expenses')->where('id', $id)->value('status'));

        // 已审单据：重复审核 / 修改 / 删除 均 422
        $this->postJson(self::BASE."/{$id}/approve")->assertStatus(422);
        $this->putJson(self::BASE."/{$id}", ['amount' => 999])->assertStatus(422);
        $this->deleteJson(self::BASE."/{$id}")->assertStatus(422);
    }

    public function test_update_and_destroy_on_draft(): void
    {
        $this->actingAsAdmin();

        $id = $this->createExpense();

        $this->putJson(self::BASE."/{$id}", ['amount' => 250.00, 'remark' => '改价'])
            ->assertOk()
            ->assertJsonPath('data.amount', '250.00'); // decimal:2 转字符串

        $this->deleteJson(self::BASE."/{$id}")->assertNoContent();
        $this->assertSame(0, DB::table('expenses')->count());
    }

    public function test_show_missing_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->getJson(self::BASE.'/999999')->assertStatus(404);
    }

    public function test_list_amount_total_equals_sum_of_details(): void
    {
        $this->actingAsAdmin();

        $amounts = [100.01, 250.50, 999.99];
        foreach ($amounts as $amount) {
            $this->createExpense(['amount' => $amount]);
        }

        $data = $this->getJson(self::BASE.'?page=1&page_size=20')->assertOk()->json('data');
        $this->assertSame(3, $data['total']);

        $sum = array_sum(array_map(fn ($row) => (float) $row['amount'], $data['list']));
        $this->assertSame(round(array_sum($amounts), 2), round($sum, 2));

        // 统计口径：已审核之和（草稿不计入）
        $this->createExpense(['amount' => 5000]); // 草稿，不进统计
        $approvedOnlyId = $this->createExpense(['amount' => 60.25]);
        $this->postJson(self::BASE."/{$approvedOnlyId}/approve")->assertOk();

        $stats = $this->getJson(self::BASE.'/statistics')->assertOk()->json('data');
        $this->assertSame(60.25, (float) $stats['total_amount']);
        $this->assertSame(1, (int) $stats['count']);
    }

    public function test_end_date_day_itself_is_included_in_list_and_statistics(): void
    {
        $this->actingAsAdmin();

        // 坑同 ProfitRangeTest：date cast 经 Eloquent 写入 sqlite 是 'YYYY-MM-DD 00:00:00'，
        // 字符串上界 `<= 'YYYY-MM-DD'` 会静默丢掉 end_date 当天那笔（MySQL DATE 列无此差异，CI 抓不到）
        $this->createExpense(['expense_date' => '2026-10-10', 'amount' => 10.00]);
        $onEndId = $this->createExpense(['expense_date' => '2026-10-15', 'amount' => 88.88]);
        $this->postJson(self::BASE."/{$onEndId}/approve")->assertOk();
        $afterEndId = $this->createExpense(['expense_date' => '2026-10-16', 'amount' => 77.77]);
        $this->postJson(self::BASE."/{$afterEndId}/approve")->assertOk();

        $range = $this->getJson(self::BASE.'?start_date=2026-10-01&end_date=2026-10-15')
            ->assertOk()->json('data');
        $this->assertSame(2, $range['total']);
        $dates = array_map(fn ($row) => substr((string) $row['expense_date'], 0, 10), $range['list']);
        $this->assertContains('2026-10-15', $dates);
        $this->assertNotContains('2026-10-16', $dates);

        // 统计（只算已审核）：88.88 那笔在 end_date 当天，必须计入；次日已审核那笔不得混入
        $stats = $this->getJson(self::BASE.'/statistics?start_date=2026-10-01&end_date=2026-10-15')
            ->assertOk()->json('data');
        $this->assertSame(1, (int) $stats['count']);
        $this->assertSame(88.88, (float) $stats['total_amount']);
    }

    public function test_export_returns_bom_csv_following_list_filters(): void
    {
        $this->actingAsAdmin();

        $this->createExpense(['expense_type' => '差旅费', 'expense_date' => '2026-10-05', 'amount' => 66.60]);
        $onEndId = $this->createExpense(['expense_type' => '租金', 'expense_date' => '2026-10-15', 'amount' => 88.88]);
        $this->postJson(self::BASE."/{$onEndId}/approve")->assertOk();
        $this->createExpense(['expense_type' => '租金', 'expense_date' => '2026-10-16', 'amount' => 77.77]);

        $res = $this->get(self::BASE.'/export?expense_type='.urlencode('租金').'&start_date=2026-10-01&end_date=2026-10-15');

        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));

        $body = $res->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('单号,费用科目,金额,费用日期,经手人,部门,状态,备注', $body);
        // end_date 当天那笔必须在；区间外（次日）与类型外（差旅费）不得混入
        $this->assertStringContainsString(',租金,88.88,2026-10-15,,', $body);
        $this->assertStringNotContainsString('77.77', $body);
        $this->assertStringNotContainsString('66.60', $body);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson(self::BASE)->assertStatus(401);
        $this->postJson(self::BASE, ['expense_type' => '租金', 'amount' => 100])->assertStatus(401);
    }
}
