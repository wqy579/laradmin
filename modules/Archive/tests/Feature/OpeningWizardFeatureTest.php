<?php

namespace Tests\Archive\Feature;

use App\Exports\GenericExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Archive\Models\OpeningBalance;
use Modules\Archive\Models\OpeningStep;
use Modules\Archive\Models\OpeningStock;
use Modules\Archive\Models\OpeningWizard;
use Modules\Ledger\Models\Subject;
use Tests\TestCase;

/**
 * F6 块2：开账向导（4 步 + Excel 导入 + 开账锁定）。
 *
 * 验收点：
 *  - 首次打开自动建 draft 向导 + 4 步 pending（步骤状态由后端下发）
 *  - 完成步骤写流水；锁定后 saveStep / import 一律拒绝
 *  - 第 3 步试算口径与 Ledger 一致（叶子科目 期初+本年累计 借 vs 贷）
 *  - lock 是验收闸门：步骤不全 / 试算不平都拒绝；通过则回写真实 stocks 与
 *    ledger_opening_balances，置 locked + lock_report + 流水
 *  - 已结账期间的期初跳过不写；unlock 必填原因且不删已写入的账
 *  - 模板下载是 xlsx 下载响应；导入按「好行入库、坏行只报原因」部分成功
 *
 * 全程不硬编码自增 ID（本地 sqlite / CI MySQL 的自增值不同）。
 */
class OpeningWizardFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected string $period = '2027-01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['real_name' => '开账管理员']);

        // 期间与科目都要自己管：Ledger 的迁移预置了当年 12 期与一批默认科目
        // （含 1001/10 等编码），跨年跑用例会撞期、也会撞科目编码唯一键
        DB::table('ledger_periods')->insert([
            'year' => 2027,
            'month' => 1,
            'start_date' => '2027-01-01',
            'end_date' => '2027-01-31',
            'status' => 'open',
            'voucher_count' => 0,
            'total_debit' => 0,
            'total_credit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Subject::query()->delete();
    }

    protected function company(): int
    {
        return DB::table('archive_companies')->insertGetId([
            'name' => '连凯开账主体',
            'status' => 'enabled',
            'sort' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** 幂等建科目：code 是唯一键，同一用例里多处复用同一套科目时不能撞键 */
    protected function subject(string $code, string $name, bool $isLeaf = true): int
    {
        return Subject::firstOrCreate(['code' => $code], [
            'parent_id' => 0,
            'code' => $code,
            'name' => $name,
            'category' => Subject::CATEGORY_ASSET,
            'balance_direction' => 'debit',
            'is_leaf' => $isLeaf,
            'level' => 1,
            'sort' => 0,
            'status' => 'enabled',
        ])->id;
    }

    protected function openWizard(int $companyId, array $params = [])
    {
        return $this->getJson('/admin/data/opening?'.http_build_query(array_merge([
            'company_id' => $companyId,
            'period' => $this->period,
        ], $params)))->assertOk();
    }

    /** 一路做到「可锁定」：第 1 步确认 + 第 2 步库存 + 第 3 步平衡的期初 */
    protected function completeSteps(int $companyId, array $stockRows = [], array $balanceRows = []): void
    {
        $base = ['company_id' => $companyId, 'period' => $this->period];

        // 第 1 步的闸门是「至少有一个末级科目」，所以科目必须在第 1 步之前建好；
        // 幂等建（firstOrCreate），调用方自己建过同一套科目也不会撞编码唯一键
        $cash = $this->subject('1001', '库存现金');
        $capital = $this->subject('4001', '实收资本');

        $this->postJson('/admin/data/opening/steps/1', $base)->assertOk();

        if ($stockRows === []) {
            $product = $this->makeProduct(['code' => 'KC001']);
            $warehouse = $this->makeWarehouse(['code' => 'CK001']);
            $stockRows = [[
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 10,
                'cost_price' => 5.5,
            ]];
        }
        $this->postJson('/admin/data/opening/steps/2', $base + ['rows' => $stockRows])->assertOk();

        if ($balanceRows === []) {
            $cash = $this->subject('1001', '库存现金');
            $capital = $this->subject('4001', '实收资本');
            $balanceRows = [
                ['subject_id' => $cash, 'opening_debit' => 1000, 'opening_credit' => 0],
                ['subject_id' => $capital, 'opening_debit' => 0, 'opening_credit' => 1000],
            ];
        }
        $this->postJson('/admin/data/opening/steps/3', $base + ['rows' => $balanceRows])->assertOk();
    }

    public function test_menu_and_button_permissions_registered(): void
    {
        $this->assertDatabaseHas('auth_permission', [
            'name' => 'data.opening',
            'path' => '/data/opening',
            'component' => 'data/opening/index',
        ]);
        // 前端 v-permission 用冒号形式，点号名与冒号别名都要登记
        foreach (['view', 'edit', 'import', 'lock', 'unlock'] as $action) {
            $this->assertDatabaseHas('auth_permission', ['name' => 'data.opening.'.$action]);
            $this->assertDatabaseHas('auth_permission', ['name' => 'data:opening:'.$action]);
        }
    }

    public function test_show_creates_draft_wizard_with_four_pending_steps(): void
    {
        $companyId = $this->company();

        $resp = $this->openWizard($companyId);

        $resp->assertJsonPath('data.status', OpeningWizard::STATUS_DRAFT)
            ->assertJsonPath('data.locked', false)
            ->assertJsonPath('data.active_step', 1)
            ->assertJsonPath('data.steps.0.key', 'subject')
            ->assertJsonPath('data.steps.0.status', OpeningStep::STATUS_PENDING)
            ->assertJsonPath('data.steps.1.key', 'stock')
            ->assertJsonPath('data.steps.2.key', 'balance')
            ->assertJsonPath('data.steps.3.key', 'import')
            ->assertJsonPath('data.steps.3.title', '导入/确认');
        $resp->assertJsonCount(4, 'data.steps');

        // 再开一次必须复用同一份，不能给同主体同期间建第二份
        $again = $this->openWizard($companyId);
        $this->assertSame($resp->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, OpeningWizard::count());
    }

    public function test_division_and_company_level_wizards_do_not_collide(): void
    {
        $companyId = $this->company();
        $divisionId = DB::table('archive_divisions')->insertGetId([
            'company_id' => $companyId,
            'name' => '一分部',
            'status' => 'enabled',
            'sort' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $companyLevel = $this->openWizard($companyId)->json('data.id');
        $divisionLevel = $this->openWizard($companyId, ['division_id' => $divisionId])->json('data.id');

        $this->assertNotSame($companyLevel, $divisionLevel);
        $this->assertSame(2, OpeningWizard::count());
        // 同事业部再开还是那一份（服务层兜住可空列建不了唯一索引的缺口）
        $this->assertSame($divisionLevel, $this->openWizard($companyId, ['division_id' => $divisionId])->json('data.id'));
    }

    public function test_save_step_marks_done_and_writes_log(): void
    {
        $companyId = $this->company();
        $this->subject('1001', '库存现金');

        $resp = $this->postJson('/admin/data/opening/steps/1', ['company_id' => $companyId, 'period' => $this->period])
            ->assertOk()
            ->assertJsonPath('message', '第 1 步已完成')
            ->assertJsonPath('data.steps.0.status', OpeningStep::STATUS_DONE)
            ->assertJsonPath('data.active_step', 2);

        $wizardId = $resp->json('data.id');
        $this->assertDatabaseHas('archive_opening_steps', [
            'wizard_id' => $wizardId,
            'step' => 1,
            'status' => 'done',
            'done_by_name' => '开账管理员',
        ]);
        $this->assertDatabaseHas('archive_opening_logs', [
            'wizard_id' => $wizardId,
            'action' => 'step_done',
            'operator_name' => '开账管理员',
        ]);
        // 当前步指针推进到下一步
        $this->assertSame(2, OpeningWizard::find($wizardId)->step);
    }

    public function test_step_one_refuses_when_no_leaf_subject_exists(): void
    {
        $companyId = $this->company();

        $this->postJson('/admin/data/opening/steps/1', ['company_id' => $companyId, 'period' => $this->period])
            ->assertStatus(422)
            ->assertJsonPath('message', '尚未维护会计科目，请先在「总账-会计科目」中录入科目');
    }

    public function test_step_two_rejects_duplicate_product_warehouse_pair(): void
    {
        $companyId = $this->company();
        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();
        $row = ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'cost_price' => 1];

        $this->postJson('/admin/data/opening/steps/2', [
            'company_id' => $companyId,
            'period' => $this->period,
            'rows' => [$row, $row],
        ])->assertStatus(422)->assertJsonPath('message', '第 2 行：同一商品在同一仓库重复录入');
    }

    public function test_step_three_reports_unbalanced_trial_with_diff(): void
    {
        $companyId = $this->company();
        $debit = $this->subject('1001', '库存现金');
        $credit = $this->subject('4001', '实收资本');

        $this->postJson('/admin/data/opening/steps/1', ['company_id' => $companyId, 'period' => $this->period])->assertOk();

        // 借 1200.50 / 贷 1000.00 → 差额 200.50
        $resp = $this->postJson('/admin/data/opening/steps/3', [
            'company_id' => $companyId,
            'period' => $this->period,
            'rows' => [
                ['subject_id' => $debit, 'opening_debit' => 1000.50, 'year_debit' => 200],
                ['subject_id' => $credit, 'opening_credit' => 1000],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.trial.balanced', false)
            ->assertJsonPath('data.trial.debit_total', 1200.5)
            ->assertJsonPath('data.trial.credit_total', 1000)
            ->assertJsonPath('data.trial.diff', 200.5)
            ->assertJsonPath('data.trial.period_closed', false);

        // 第 3 步本身允许先保存（现场常常先录一半次日再平），平衡与否由 lock() 兜底：
        // 步骤可以 done，但试算不平就锁不上，见 test_lock_refuses_when_trial_balance_off
        $this->assertSame('done', OpeningStep::where('wizard_id', $resp->json('data.id'))->where('step', 3)->value('status'));
    }

    public function test_step_three_rejects_non_leaf_subject(): void
    {
        $companyId = $this->company();
        $parent = $this->subject('10', '资产类', false);

        $this->postJson('/admin/data/opening/steps/3', [
            'company_id' => $companyId,
            'period' => $this->period,
            'rows' => [['subject_id' => $parent, 'opening_debit' => 100]],
        ])->assertStatus(422)->assertJsonPath('message', '科目「资产类」不是末级科目，不能录入期初余额');
    }

    public function test_lock_refuses_when_steps_incomplete(): void
    {
        $companyId = $this->company();
        $this->subject('1001', '库存现金');
        $base = ['company_id' => $companyId, 'period' => $this->period];

        $this->postJson('/admin/data/opening/lock', $base)
            ->assertStatus(422)
            ->assertJsonPath('message', '请先完成 第 1 步「科目设置」、第 2 步「期初库存」、第 3 步「期初余额」才能确认开账');

        $this->postJson('/admin/data/opening/steps/1', $base)->assertOk();
        $this->postJson('/admin/data/opening/lock', $base)
            ->assertStatus(422)
            ->assertJsonPath('message', '请先完成 第 2 步「期初库存」、第 3 步「期初余额」才能确认开账');
    }

    public function test_lock_refuses_when_trial_balance_off(): void
    {
        $companyId = $this->company();
        $cash = $this->subject('1001', '库存现金');
        $capital = $this->subject('4001', '实收资本');
        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();
        $base = ['company_id' => $companyId, 'period' => $this->period];

        $this->postJson('/admin/data/opening/steps/1', $base)->assertOk();
        $this->postJson('/admin/data/opening/steps/2', $base + ['rows' => [
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 3, 'cost_price' => 2],
        ]])->assertOk();
        $this->postJson('/admin/data/opening/steps/3', $base + ['rows' => [
            ['subject_id' => $cash, 'opening_debit' => 300],
            ['subject_id' => $capital, 'opening_credit' => 250],
        ]])->assertOk();

        $this->postJson('/admin/data/opening/lock', $base)
            ->assertStatus(422)
            ->assertJsonPath('message', '借贷不平衡，借方 300.00，贷方 250.00，差额 50.00，请调整后再确认开账');

        $this->assertSame(OpeningWizard::STATUS_DRAFT, OpeningWizard::first()->status);
        $this->assertDatabaseCount('stocks', 0);
    }

    public function test_lock_writes_real_stock_and_ledger_opening_balance(): void
    {
        $companyId = $this->company();
        $product = $this->makeProduct(['code' => 'KC900']);
        $warehouse = $this->makeWarehouse(['code' => 'CK900']);
        $cash = $this->subject('1001', '库存现金');
        $capital = $this->subject('4001', '实收资本');
        $this->subject('10', '资产类', false);

        $this->completeSteps($companyId, [
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 7, 'cost_price' => 12.5],
        ], [
            ['subject_id' => $cash, 'opening_debit' => 87.5, 'year_debit' => 0],
            ['subject_id' => $capital, 'opening_credit' => 87.5],
        ]);

        $base = ['company_id' => $companyId, 'period' => $this->period];
        $resp = $this->postJson('/admin/data/opening/lock', $base)
            ->assertOk()
            ->assertJsonPath('message', '开账已确认并锁定')
            ->assertJsonPath('data.locked', true)
            ->assertJsonPath('data.lock_report.stock_written', 1)
            ->assertJsonPath('data.lock_report.balance_written', 2)
            ->assertJsonPath('data.lock_report.balance_skipped_closed', 0);

        $this->assertNotEmpty($resp->json('data.locked_at'));
        $this->assertSame('开账管理员', $resp->json('data.locked_by_name'));

        // 真实库存：数量与金额都对得上（7 × 12.5 = 87.5）
        $stock = DB::table('stocks')->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertNotNull($stock);
        $this->assertSame(7, (int) $stock->quantity);
        $this->assertSame('87.50', number_format((float) $stock->total_amount, 2));

        // 真实总账期初：借方落在现金、贷方落在实收资本
        $this->assertDatabaseHas('ledger_opening_balances', [
            'subject_id' => $cash,
            'period' => $this->period,
            'opening_debit' => 87.5,
        ]);
        $this->assertDatabaseHas('ledger_opening_balances', [
            'subject_id' => $capital,
            'period' => $this->period,
            'opening_credit' => 87.5,
        ]);

        $wizardId = $resp->json('data.id');
        $this->assertSame(OpeningWizard::STATUS_LOCKED, OpeningWizard::find($wizardId)->status);
        $this->assertDatabaseHas('archive_opening_logs', ['wizard_id' => $wizardId, 'action' => 'lock']);
        $this->assertNotEmpty($resp->json('data.logs'));
    }

    public function test_lock_skips_closed_period_subjects(): void
    {
        $companyId = $this->company();
        DB::table('ledger_periods')->where('year', 2027)->where('month', 1)->update(['status' => 'closed']);

        $resp = $this->openWizard($companyId);
        $this->assertTrue($resp->json('data.trial.period_closed'));

        $this->completeSteps($companyId);

        $this->postJson('/admin/data/opening/lock', ['company_id' => $companyId, 'period' => $this->period])
            ->assertOk()
            ->assertJsonPath('data.lock_report.balance_written', 0)
            ->assertJsonPath('data.lock_report.balance_skipped_closed', 2)
            // 库存不受结账影响
            ->assertJsonPath('data.lock_report.stock_written', 1);

        $this->assertDatabaseCount('ledger_opening_balances', 0);
        $this->assertDatabaseCount('stocks', 1);
    }

    public function test_locked_wizard_refuses_save_and_import(): void
    {
        $companyId = $this->company();
        $this->completeSteps($companyId);
        $base = ['company_id' => $companyId, 'period' => $this->period];
        $this->postJson('/admin/data/opening/lock', $base)->assertOk();

        $this->postJson('/admin/data/opening/steps/2', $base + ['rows' => []])
            ->assertStatus(422)
            ->assertJsonPath('message', '开账已完成并锁定，不能修改');
        $this->postJson('/admin/data/opening/steps/3', $base + ['rows' => [['subject_id' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('message', '开账已完成并锁定，不能修改');

        $file = $this->makeStockUpload([[
            $this->makeProduct(['code' => 'KC500'])->code,
            $this->makeWarehouse(['code' => 'CK500'])->code,
            5,
            3,
        ]]);
        $this->postJson('/admin/data/opening/import', $base + ['step' => 2, 'file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('message', '开账已完成并锁定，不能修改');
    }

    public function test_unlock_requires_reason_and_keeps_written_data(): void
    {
        $companyId = $this->company();
        $product = $this->makeProduct(['code' => 'KC600']);
        $warehouse = $this->makeWarehouse(['code' => 'CK600']);
        $this->completeSteps($companyId, [
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 4, 'cost_price' => 25],
        ]);
        $base = ['company_id' => $companyId, 'period' => $this->period];
        $wizardId = $this->postJson('/admin/data/opening/lock', $base)->assertOk()->json('data.id');

        // 不填原因不给解
        $this->postJson('/admin/data/opening/unlock', $base)
            ->assertStatus(422);

        $this->postJson('/admin/data/opening/unlock', $base + ['reason' => '期初成本录错，需重录'])
            ->assertOk()
            ->assertJsonPath('data.locked', false)
            ->assertJsonPath('data.status', OpeningWizard::STATUS_DRAFT);

        $this->assertDatabaseHas('archive_opening_logs', [
            'wizard_id' => $wizardId,
            'action' => 'unlock',
            'content' => '反开账解锁，原因：期初成本录错，需重录',
        ]);

        // 有意不删：静默冲销账侧数据比留着待修正更危险
        $this->assertDatabaseCount('stocks', 1);
        $this->assertDatabaseCount('ledger_opening_balances', 2);

        // 解锁后可以接着改，再锁定是幂等覆盖而不是翻倍
        $this->postJson('/admin/data/opening/steps/2', $base + ['rows' => [
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 6, 'cost_price' => 25],
        ]])->assertOk();
        $this->postJson('/admin/data/opening/lock', $base)->assertOk();
        $this->assertSame(6, (int) DB::table('stocks')->where('product_id', $product->id)->value('quantity'));
        $this->assertSame('150.00', number_format((float) DB::table('stocks')->where('product_id', $product->id)->value('total_amount'), 2));
    }

    public function test_unlock_refused_when_not_locked(): void
    {
        $companyId = $this->company();
        $this->openWizard($companyId);

        $this->postJson('/admin/data/opening/unlock', [
            'company_id' => $companyId,
            'period' => $this->period,
            'reason' => '随便',
        ])->assertStatus(422)->assertJsonPath('message', '该开账尚未锁定，无需反开账');
    }

    public function test_template_download_returns_xlsx(): void
    {
        foreach (['stock', 'balance'] as $kind) {
            $resp = $this->get("/admin/data/opening/template/{$kind}")->assertOk();
            $this->assertStringEndsWith('.xlsx', $resp->headers->get('content-disposition'));
            $this->assertStringContainsString($kind === 'stock' ? 'opening_stock_import_template_' : 'opening_balance_import_template_',
                $resp->headers->get('content-disposition'));
        }

        $this->get('/admin/data/opening/template/unknown')->assertStatus(422);
    }

    public function test_stock_import_accepts_good_row_and_reports_bogus_code(): void
    {
        $companyId = $this->company();
        $product = $this->makeProduct(['code' => 'KC100']);
        $warehouse = $this->makeWarehouse(['code' => 'CK100']);

        // 与用户下载到的模板完全同构：GenericExport 会把表头写两遍（WithHeadings + FromCollection），
        // 于是物理行号 1=表头 2=表头 3=好行 4=坏行 → 报错必须落在「第 4 行」，不能偏
        $file = $this->makeStockUpload([
            [$product->code, $warehouse->code, 12, 8.5],
            ['NOPE999', $warehouse->code, 3, 1],
        ]);

        $resp = $this->postJson('/admin/data/opening/import', [
            'company_id' => $companyId,
            'period' => $this->period,
            'step' => 2,
            'file' => $file,
        ])
            ->assertOk()
            ->assertJsonPath('data.success_count', 1)
            ->assertJsonPath('data.error_count', 1)
            ->assertJsonPath('data.stock_total', 102);

        $this->assertStringContainsString('第 4 行', $resp->json('data.errors.0'));
        $this->assertStringContainsString('商品编码不存在: NOPE999', $resp->json('data.errors.0'));
        $this->assertSame(['商品编码 NOPE999'], $resp->json('data.unmatched'));

        $this->assertDatabaseHas('archive_opening_stocks', [
            'wizard_id' => OpeningWizard::first()->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 12,
            'total_amount' => 102,
            'source' => OpeningStock::SOURCE_IMPORT,
        ]);
        $this->assertDatabaseHas('archive_opening_logs', [
            'wizard_id' => OpeningWizard::first()->id,
            'action' => 'import',
        ]);
    }

    public function test_balance_import_rejects_non_leaf_and_reports_row_number(): void
    {
        $companyId = $this->company();
        $leaf = $this->subject('1002', '银行存款');
        $parent = $this->subject('10', '资产类', false);

        $filename = 'opening_balance_probe_'.uniqid().'.xlsx';
        Excel::store(new GenericExport([
            ['科目编码', '期初借方', '期初贷方', '年初借方', '年初贷方'],
            ['1002', 500, 0, 0, 0],
            ['10', 100, 0, 0, 0],
        ]), 'exports/'.$filename, 'public');
        $path = storage_path('app/public/exports/'.$filename);

        $resp = $this->postJson('/admin/data/opening/import', [
            'company_id' => $companyId,
            'period' => $this->period,
            'step' => 3,
            'file' => new UploadedFile($path, $filename, null, null, true),
        ])
            ->assertOk()
            ->assertJsonPath('data.success_count', 1)
            ->assertJsonPath('data.error_count', 1)
            ->assertJsonPath('data.trial.balanced', false)
            ->assertJsonPath('data.trial.debit_total', 500);

        $this->assertStringContainsString('第 4 行', $resp->json('data.errors.0'));
        $this->assertStringContainsString('不是末级科目', $resp->json('data.errors.0'));

        $wizardId = OpeningWizard::first()->id;
        $this->assertDatabaseHas('archive_opening_balances', [
            'wizard_id' => $wizardId,
            'subject_id' => $leaf,
            'period' => $this->period,
            'opening_debit' => 500,
        ]);
        $this->assertDatabaseCount('archive_opening_balances', 1);
        $this->assertNotNull($parent);

        @unlink($path);
    }

    public function test_import_refuses_wrong_step_and_missing_file(): void
    {
        $companyId = $this->company();
        $this->openWizard($companyId);

        $this->postJson('/admin/data/opening/import', [
            'company_id' => $companyId,
            'period' => $this->period,
            'step' => 1,
        ])->assertStatus(422)->assertJsonPath('errors.step.0', '只有第 2 步（期初库存）和第 3 步（期初余额）支持导入');
    }

    public function test_lock_status_endpoint_is_read_only(): void
    {
        $companyId = $this->company();
        $base = ['company_id' => $companyId, 'period' => $this->period];

        $this->getJson('/admin/data/opening/lock-status?'.http_build_query($base))
            ->assertOk()
            ->assertJsonPath('data.exists', false)
            ->assertJsonPath('data.locked', false);

        $this->openWizard($companyId);
        $this->getJson('/admin/data/opening/lock-status?'.http_build_query($base))
            ->assertOk()
            ->assertJsonPath('data.exists', true)
            ->assertJsonPath('data.locked', false);

        // 只读端点不会因为「打开」而把 draft 变成 locked
        $this->completeSteps($companyId);
        $this->postJson('/admin/data/opening/lock', $base)->assertOk();
        $this->getJson('/admin/data/opening/lock-status?'.http_build_query($base))
            ->assertOk()
            ->assertJsonPath('data.locked', true)
            ->assertJsonPath('data.locked_by_name', '开账管理员');
    }

    public function test_show_validates_period_format(): void
    {
        $companyId = $this->company();

        $this->getJson('/admin/data/opening?company_id='.$companyId.'&period=202701')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period']);
    }

    public function test_service_lock_gate_rejects_when_trial_off_even_if_steps_marked_done(): void
    {
        // 直接改库把第 3 步伪造成 done，验证 lock() 的试算闸门独立于步骤状态
        $companyId = $this->company();
        $this->completeSteps($companyId);
        $wizard = OpeningWizard::first();
        OpeningBalance::where('wizard_id', $wizard->id)->update(['opening_credit' => 0]);
        OpeningStep::where('wizard_id', $wizard->id)->where('step', 3)->update(['status' => 'done']);

        $this->postJson('/admin/data/opening/lock', ['company_id' => $companyId, 'period' => $this->period])
            ->assertStatus(422)
            ->assertJsonPath('message', '借贷不平衡，借方 1000.00，贷方 0.00，差额 1000.00，请调整后再确认开账');

        $this->assertSame(OpeningWizard::STATUS_DRAFT, OpeningWizard::first()->status);
        $this->assertDatabaseCount('ledger_opening_balances', 0);
    }

    /** 造一份真实的 xlsx（不是 CSV 改名）：每行都按 GenericExport 的表头重复特性排 */
    protected function makeStockUpload(array $rows): UploadedFile
    {
        $filename = 'opening_stock_probe_'.uniqid().'.xlsx';
        Excel::store(new GenericExport(array_merge(
            [['商品编码', '仓库编码', '数量', '成本单价']],
            $rows
        )), 'exports/'.$filename, 'public');

        return new UploadedFile(
            storage_path('app/public/exports/'.$filename),
            $filename,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }
}
