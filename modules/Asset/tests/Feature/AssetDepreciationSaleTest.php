<?php

namespace Tests\Asset\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Asset\Models\AssetCard;
use Modules\Asset\Models\AssetDepreciation;
use Modules\Asset\Models\AssetSale;
use Modules\Ledger\Models\Voucher;
use Modules\Ledger\Seeders\LedgerSubjectSeeder;
use Tests\TestCase;

/**
 * D4 验收：资产折旧 + 资产变卖
 *
 *  - 折旧：月度批量计提，同月锁定不可重复；本月折旧额 = 原值×(1−残值率%)÷预计月数，
 *    封顶剩余净值；计提后累计折旧/净值回写卡片；按使用部门拆分凭证（借 管理费用/贷 累计折旧）。
 *  - 变卖：损益 = 变卖价 − 净值（正=盈利 营业外收入，负=亏损 营业外支出）；
 *    仅「在用」可变卖；保存后卡片状态置「已售」、净值清零；
 *    处置凭证（借 固定资产清理 / 贷 固定资产 + 累计折旧 + 银行存款 + 损益）可选且借贷平衡。
 */
class AssetDepreciationSaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();

        // 凭证联动用到 1601/1602/1002/1606/6301/6711/6602 末级科目
        $seeder = new LedgerSubjectSeeder;
        $seeder->setContainer(app());
        $seeder->callWith([LedgerSubjectSeeder::class]);
    }

    /** 按科目聚合借方/贷方金额（同一科目可能多条分录，需求和） */
    private function byCode($entries): array
    {
        $result = [];
        foreach ($entries as $e) {
            $code = $e->subject_code;
            $result[$code]['debit'] = ($result[$code]['debit'] ?? 0) + (float) $e->debit_amount;
            $result[$code]['credit'] = ($result[$code]['credit'] ?? 0) + (float) $e->credit_amount;
        }

        return $result;
    }

    /** 直接造一张在用卡片（绕开购置单，聚焦 D4 业务） */
    private function makeCard(array $overrides = []): AssetCard
    {
        $card = AssetCard::create(array_merge([
            'asset_no' => 'ZC-202610-9001',
            'asset_name' => '注塑机',
            'original_value' => 10000,
            'use_months' => 60,
            'residual_rate' => 5,
            'dep_method' => 'straight',
            'accumulated_dep' => 0,
            'net_value' => 10000,
            'department_id' => 1,
            'department_name' => '生产部',
            'status' => AssetCard::STATUS_INUSE,
            'acquire_date' => '2026-10-01',
        ], $overrides));

        return $card;
    }

    public function test_depreciation_preview_computes_formula_and_residual_cap(): void
    {
        // 原值 10000，残值率 5%，60 个月 → 10000×0.95÷60 = 158.33
        $this->makeCard();
        // 残值率 0，1 个月 → 5000；但封顶剩余净值 4000 → 计提 4000
        $this->makeCard([
            'asset_no' => 'ZC-202610-9002',
            'asset_name' => '叉车',
            'original_value' => 5000,
            'use_months' => 1,
            'residual_rate' => 0,
            'net_value' => 4000,
        ]);
        // 闲置不参与
        $this->makeCard([
            'asset_no' => 'ZC-202610-9003',
            'asset_name' => '闲置设备',
            'status' => AssetCard::STATUS_IDLE,
        ]);

        $res = $this->getJson('/admin/asset/depreciations/preview?month=2026-10')->assertOk()->json('data');

        $this->assertSame(2, $res['count']);
        $byNo = collect($res['list'])->keyBy('code');
        $this->assertEqualsWithDelta(158.33, $byNo['ZC-202610-9001']['month_amount'], 0.001);
        $this->assertEqualsWithDelta(9841.67, $byNo['ZC-202610-9001']['after_net'], 0.001);
        // 封顶剩余净值 4000
        $this->assertEqualsWithDelta(4000, $byNo['ZC-202610-9002']['month_amount'], 0.001);
        $this->assertEqualsWithDelta(1000, $byNo['ZC-202610-9002']['after_net'], 0.001);
    }

    public function test_depreciation_run_locks_period_and_writes_cards(): void
    {
        $card = $this->makeCard();
        $this->makeCard([
            'asset_no' => 'ZC-202610-9002',
            'asset_name' => '叉车',
            'original_value' => 12000,
            'net_value' => 12000,
            'department_id' => 2,
            'department_name' => '仓储部',
        ]);

        $run = $this->postJson('/admin/asset/depreciations', ['month' => '2026-10'])
            ->assertOk()->json('data');

        $this->assertSame('ZJ-202610-0001', $run['batch_no']);
        $this->assertSame(2, $run['count']);
        // 158.33 + 12000×0.95÷60=190 → 348.33
        $this->assertEqualsWithDelta(348.33, $run['amount'], 0.01);

        // 卡片回写：累计折旧累加、净值扣减
        $card->refresh();
        $this->assertSame('158.33', number_format((float) $card->accumulated_dep, 2, '.', ''));
        $this->assertSame('9841.67', number_format((float) $card->net_value, 2, '.', ''));

        // 同月锁定：重复执行被拒
        $this->postJson('/admin/asset/depreciations', ['month' => '2026-10'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['month']);
        $this->assertSame(2, AssetDepreciation::count());

        // 下月可正常计提（不锁定）
        $this->postJson('/admin/asset/depreciations', ['month' => '2026-11'])
            ->assertOk()->assertJsonPath('data.batch_no', 'ZJ-202611-0001');
    }

    public function test_depreciation_splits_voucher_per_department(): void
    {
        // 生产部 2 台 + 仓储部 1 台 → 两张部门凭证
        $this->makeCard(); // 生产部 10000 → 158.33
        $this->makeCard([
            'asset_no' => 'ZC-202610-9002',
            'asset_name' => '车床',
            'original_value' => 6000,
            'net_value' => 6000,
            'department_id' => 1,
            'department_name' => '生产部',
        ]); // 生产部 6000×0.95÷60=95
        $this->makeCard([
            'asset_no' => 'ZC-202610-9003',
            'asset_name' => '叉车',
            'original_value' => 12000,
            'net_value' => 12000,
            'department_id' => 2,
            'department_name' => '仓储部',
        ]); // 仓储部 190

        $run = $this->postJson('/admin/asset/depreciations', ['month' => '2026-10'])
            ->assertOk()->json('data');

        $this->assertSame(2, count($run['voucher_ids']));
        $this->assertNull($run['voucher_error']);

        // 每张凭证：借 6602 / 贷 1602，且借贷平衡
        foreach ($run['voucher_ids'] as $voucherId) {
            $voucher = Voucher::with('entries')->find($voucherId);
            $this->assertSame((float) $voucher->total_debit, (float) $voucher->total_credit);
            $byCode = collect($voucher->entries)->keyBy('subject_code');
            $this->assertSame(
                number_format((float) $byCode['6602']->debit_amount, 2, '.', ''),
                number_format((float) $byCode['1602']->credit_amount, 2, '.', '')
            );
            $this->assertStringContainsString('固定资产折旧（2026-10）', (string) $voucher->remark);
        }

        // 部门合计分别 253.33 与 190
        $amounts = Voucher::whereIn('id', $run['voucher_ids'])->with('entries')->get()
            ->map(fn ($v) => (float) collect($v->entries)->where('subject_code', '6602')->first()->debit_amount)
            ->sort()->values();
        $this->assertEqualsWithDelta(190, $amounts[0], 0.01);
        $this->assertEqualsWithDelta(253.33, $amounts[1], 0.01);

        // 折旧记录按部门回写凭证号
        $this->assertSame(
            2,
            AssetDepreciation::where('batch_no', $run['batch_no'])->where('department_id', 1)->whereNotNull('voucher_id')->count()
        );
        $this->assertSame(
            1,
            AssetDepreciation::where('batch_no', $run['batch_no'])->where('department_id', 2)->whereNotNull('voucher_id')->count()
        );
    }

    public function test_depreciation_requires_assets_and_valid_month(): void
    {
        $this->postJson('/admin/asset/depreciations', ['month' => '2026-10'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['month']);

        $this->postJson('/admin/asset/depreciations', ['month' => '2026-13'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['month']);

        $this->postJson('/admin/asset/depreciations', ['month' => '202610'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['month']);

        $this->assertSame(0, AssetDepreciation::count());
    }

    public function test_sale_computes_gain_loss_and_marks_card_sold(): void
    {
        $card = $this->makeCard(['original_value' => 10000, 'net_value' => 8000, 'accumulated_dep' => 2000]);

        // 盈利：卖 9500，净值 8000 → 盈利 1500
        $res = $this->postJson('/admin/asset/sales', [
            'asset_card_id' => $card->id,
            'sale_date' => '2026-10-20',
            'sale_amount' => 9500,
            'closing_fee' => 0,
            'buyer' => '连凯机电',
            'handler' => '张三',
            'register_voucher' => false,
        ])->assertOk()->json('data');

        $this->assertSame('BM-202610-0001', $res['sale_no']);
        $this->assertEqualsWithDelta(1500, $res['pnl'], 0.001);
        $this->assertNull($res['voucher_id']);

        // 卡片置已售、净值清零
        $card->refresh();
        $this->assertSame(AssetCard::STATUS_SOLD, $card->status);
        $this->assertSame('0.00', number_format((float) $card->net_value, 2, '.', ''));
        // 累计折旧定格，不再参与折旧
        $this->assertSame('2000.00', number_format((float) $card->accumulated_dep, 2, '.', ''));

        // 变卖流水
        $list = $this->getJson('/admin/asset/sales')->assertOk()->json('data');
        $this->assertSame(1, $list['total']);
        $this->assertEqualsWithDelta(1500, $list['list'][0]['pnl'], 0.001);
        $this->assertEqualsWithDelta(8000, $list['list'][0]['net_value'], 0.001);
    }

    public function test_sale_loss_when_below_net_value(): void
    {
        $card = $this->makeCard(['original_value' => 10000, 'net_value' => 8000, 'accumulated_dep' => 2000]);

        $res = $this->postJson('/admin/asset/sales', [
            'asset_card_id' => $card->id,
            'sale_date' => '2026-10-20',
            'sale_amount' => 6500,
            'buyer' => '回收站',
            'register_voucher' => false,
        ])->assertOk()->json('data');

        // 亏损 1500
        $this->assertEqualsWithDelta(-1500, $res['pnl'], 0.001);
        $this->assertSame('BM-202610-0001', $res['sale_no']);
    }

    public function test_sale_only_inuse_cards_and_sold_excluded_from_options(): void
    {
        $inuse = $this->makeCard();
        $this->makeCard([
            'asset_no' => 'ZC-202610-9002',
            'asset_name' => '闲置设备',
            'status' => AssetCard::STATUS_IDLE,
        ]);

        // 闲置不可变卖
        $idle = AssetCard::where('status', AssetCard::STATUS_IDLE)->first();
        $this->postJson('/admin/asset/sales', [
            'asset_card_id' => $idle->id,
            'sale_date' => '2026-10-20',
            'sale_amount' => 100,
            'buyer' => '买方',
        ])->assertStatus(422)->assertJsonValidationErrors(['asset_card_id']);
        $this->assertSame(0, AssetSale::count());

        // 已售资产不能再卖
        $this->postJson('/admin/asset/sales', [
            'asset_card_id' => $inuse->id,
            'sale_date' => '2026-10-20',
            'sale_amount' => 100,
            'buyer' => '买方',
        ])->assertOk();
        $this->postJson('/admin/asset/sales', [
            'asset_card_id' => $inuse->id,
            'sale_date' => '2026-10-21',
            'sale_amount' => 100,
            'buyer' => '买方',
        ])->assertStatus(422)->assertJsonValidationErrors(['asset_card_id']);
        $this->assertSame(1, AssetSale::count());

        // options 只返回在用；本卡片已售 → 已无可选
        $options = $this->getJson('/admin/asset/sales/options')->assertOk()->json('data');
        $this->assertSame([], collect($options['cards'])->pluck('id')->all());
    }

    public function test_sale_voucher_balances_and_registers(): void
    {
        $card = $this->makeCard(['original_value' => 10000, 'net_value' => 8000, 'accumulated_dep' => 2000]);

        $baseline = Voucher::count();

        $res = $this->postJson('/admin/asset/sales', [
            'asset_card_id' => $card->id,
            'sale_date' => '2026-10-20',
            'sale_amount' => 9500,
            'closing_fee' => 200,
            'buyer' => '连凯机电',
            'register_voucher' => true,
        ])->assertOk()->json('data');

        $this->assertNotNull($res['voucher_id']);
        $this->assertSame($baseline + 1, Voucher::count());

        $voucher = Voucher::with('entries')->find($res['voucher_id']);
        $this->assertSame((float) $voucher->total_debit, (float) $voucher->total_credit);
        $this->assertStringContainsString('固定资产变卖：BM-202610-0001', (string) $voucher->remark);

        $byCode = $this->byCode($voucher->entries);
        // 转出：借 1606 净值 8000 + 借 1602 2000 + 贷 1601 10000
        $this->assertEqualsWithDelta(2000, $byCode['1602']['debit'], 0.001);
        $this->assertEqualsWithDelta(10000, $byCode['1601']['credit'], 0.001);
        // 收款：借 1002 9500，清理费贷 1002 200
        $this->assertEqualsWithDelta(9500, $byCode['1002']['debit'], 0.001);
        $this->assertEqualsWithDelta(200, $byCode['1002']['credit'], 0.001);
        // 损益：净收益 = 9500 − 8000 − 200 = 1300 → 贷 6301
        $this->assertEqualsWithDelta(1300, $byCode['6301']['credit'], 0.001);

        // 1606 清理科目借贷均 9500（借 8000净值 + 200清理费 + 1300损益 = 贷 9500变卖价），净额为零
        $this->assertEqualsWithDelta(9500, $byCode['1606']['debit'], 0.001);
        $this->assertEqualsWithDelta(9500, $byCode['1606']['credit'], 0.001);
    }

    public function test_sale_voucher_loss_branch_uses_expense(): void
    {
        // 亏损卖 6500，净值 8000，清理费 0 → 损失 1500 → 借 6711
        $card = $this->makeCard(['original_value' => 10000, 'net_value' => 8000, 'accumulated_dep' => 2000]);

        $res = $this->postJson('/admin/asset/sales', [
            'asset_card_id' => $card->id,
            'sale_date' => '2026-10-20',
            'sale_amount' => 6500,
            'buyer' => '回收站',
            'register_voucher' => true,
        ])->assertOk()->json('data');

        $voucher = Voucher::with('entries')->find($res['voucher_id']);
        $this->assertSame((float) $voucher->total_debit, (float) $voucher->total_credit);
        $byCode = $this->byCode($voucher->entries);
        // 损失 1500 → 借 6711；1606 借贷均 8000（借净值 8000 = 贷变卖价 6500 + 贷损失 1500），净额清零
        $this->assertEqualsWithDelta(1500, $byCode['6711']['debit'], 0.001);
        $this->assertEqualsWithDelta(8000, $byCode['1606']['credit'], 0.001);
        $this->assertEqualsWithDelta(8000, $byCode['1606']['debit'], 0.001);
        $this->assertArrayNotHasKey('6301', $byCode);
    }

    public function test_sale_validates_required_fields(): void
    {
        $this->postJson('/admin/asset/sales', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asset_card_id', 'sale_date', 'sale_amount', 'buyer']);

        $this->postJson('/admin/asset/sales', [
            'asset_card_id' => 999999,
            'sale_date' => '2026-10-20',
            'sale_amount' => 100,
            'buyer' => '买方',
        ])->assertStatus(422)->assertJsonValidationErrors(['asset_card_id']);

        $this->assertSame(0, AssetSale::count());
    }
}
