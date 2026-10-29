<?php

namespace Tests\Asset\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Asset\Models\AssetCard;
use Modules\Asset\Models\AssetPurchase;
use Modules\Ledger\Models\Voucher;
use Modules\Ledger\Seeders\LedgerSubjectSeeder;
use Tests\TestCase;

/**
 * D3 验收：固定资产台账 + 购置单
 *
 *  - 原值 = 数量×单价 由后端重算；前端传值不符直接拒收
 *  - 购置保存生成 N 张卡片，单台净值 = 原值 = 单价、累计折旧 0
 *  - 台账卡片视图与列表视图同一数据源，条数与金额完全一致
 *  - 现金支出凭证仅在勾选时生成，未勾选不影响保存
 */
class AssetFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();

        // 凭证联动要用到 1601 固定资产 / 1001 库存现金 两个末级科目
        $seeder = new LedgerSubjectSeeder;
        $seeder->setContainer(app());
        $seeder->callWith([LedgerSubjectSeeder::class]);
    }

    private function purchasePayload(array $overrides = []): array
    {
        return array_merge([
            'purchase_date' => '2026-10-15',
            'asset_name' => '注塑机',
            'spec' => 'JS-100T',
            'quantity' => 3,
            'unit_price' => 1000,
            'use_months' => 60,
            'residual_rate' => 5,
            'dep_method' => 'straight',
            'supplier' => '连凯机电',
        ], $overrides);
    }

    public function test_store_recomputes_original_value_instead_of_trusting_client(): void
    {
        $rejected = $this->postJson('/admin/asset/purchases', $this->purchasePayload(['original_value' => 1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['original_value']);
        $this->assertStringContainsString('数量×单价', (string) $rejected->json('errors.original_value.0'));
        $this->assertSame(0, AssetPurchase::count());

        // 不传原值也要按 数量×单价 落库
        $saved = $this->postJson('/admin/asset/purchases', $this->purchasePayload(['original_value' => null]))
            ->assertOk();
        $this->assertEqualsWithDelta(3000, $saved->json('data.original_value'), 0.001);

        $purchase = AssetPurchase::first();
        $this->assertSame('3000.00', number_format((float) $purchase->original_value, 2, '.', ''));
        $this->assertSame('GZ-202610-0001', $purchase->purchase_no);
    }

    public function test_store_generates_one_card_per_unit_with_net_equal_original(): void
    {
        $this->postJson('/admin/asset/purchases', $this->purchasePayload())
            ->assertOk()
            ->assertJsonPath('data.cards', 3);

        $purchase = AssetPurchase::first();
        $cards = AssetCard::where('purchase_id', $purchase->id)->orderBy('id')->get();

        $this->assertCount(3, $cards);
        $this->assertSame(
            ['ZC-202610-0001', 'ZC-202610-0002', 'ZC-202610-0003'],
            $cards->pluck('asset_no')->all()
        );
        foreach ($cards as $card) {
            // 单台原值 = 单价；净值 = 原值；累计折旧 = 0
            $this->assertSame('1000.00', number_format((float) $card->original_value, 2, '.', ''));
            $this->assertSame('0.00', number_format((float) $card->accumulated_dep, 2, '.', ''));
            $this->assertSame('1000.00', number_format((float) $card->net_value, 2, '.', ''));
            $this->assertSame(AssetCard::STATUS_INUSE, $card->status);
            $this->assertSame('2026-10-15', $card->acquire_date->toDateString());
            $this->assertSame('JS-100T', $card->spec);
        }
    }

    public function test_card_no_continues_across_purchases_in_same_month(): void
    {
        $this->postJson('/admin/asset/purchases', $this->purchasePayload())->assertOk();
        $this->postJson('/admin/asset/purchases', $this->purchasePayload([
            'asset_name' => '叉车',
            'quantity' => 2,
            'unit_price' => 500,
        ]))->assertOk()->assertJsonPath('data.purchase_no', 'GZ-202610-0002');

        $this->assertSame(
            ['ZC-202610-0001', 'ZC-202610-0002', 'ZC-202610-0003', 'ZC-202610-0004', 'ZC-202610-0005'],
            AssetCard::orderBy('id')->pluck('asset_no')->all()
        );
    }

    public function test_voucher_only_when_checked_and_save_survives_without_it(): void
    {
        // 未勾选：购置单正常保存，且一张凭证都不生成
        $this->postJson('/admin/asset/purchases', $this->purchasePayload(['register_voucher' => false]))
            ->assertOk()
            ->assertJsonPath('data.voucher_id', null);
        $this->assertNull(AssetPurchase::first()->voucher_id);
        $this->assertSame(0, Voucher::count());

        // 勾选：生成一张「借固定资产 / 贷库存现金」的平衡凭证并回写 voucher_id
        $this->postJson('/admin/asset/purchases', $this->purchasePayload([
            'asset_name' => '货车',
            'quantity' => 1,
            'unit_price' => 88000,
            'register_voucher' => true,
        ]))->assertOk()->assertJsonPath('data.voucher_error', null);

        $second = AssetPurchase::orderByDesc('id')->first();
        $this->assertNotNull($second->voucher_id);
        $this->assertSame(1, Voucher::count());

        $voucher = Voucher::with('entries')->find($second->voucher_id);
        $this->assertSame('88000.00', number_format((float) $voucher->total_debit, 2, '.', ''));
        $this->assertSame((float) $voucher->total_debit, (float) $voucher->total_credit);
        $this->assertStringContainsString($second->purchase_no, (string) $voucher->remark);

        $byCode = collect($voucher->entries)->keyBy('subject_code');
        $this->assertSame('88000.00', number_format((float) $byCode['1601']->debit_amount, 2, '.', ''));
        $this->assertSame('88000.00', number_format((float) $byCode['1001']->credit_amount, 2, '.', ''));

        // 购置流水上「已登记」标记只认 voucher_id
        $this->postJson('/admin/asset/purchases', $this->purchasePayload(['register_voucher' => true]))
            ->assertOk();
        $r = $this->getJson('/admin/asset/purchases')->assertOk();
        $flagged = collect($r->json('data.list'))->pluck('voucher_id', 'purchase_no');
        $this->assertNull($flagged['GZ-202610-0001']);
        $this->assertNotNull($flagged['GZ-202610-0002']);
    }

    public function test_ledger_card_view_and_list_view_share_identical_rows_and_amounts(): void
    {
        $this->postJson('/admin/asset/purchases', $this->purchasePayload(['quantity' => 2, 'unit_price' => 100]))->assertOk();
        $this->postJson('/admin/asset/purchases', $this->purchasePayload([
            'asset_name' => '叉车',
            'quantity' => 3,
            'unit_price' => 200,
        ]))->assertOk();

        // 卡片视图与列表视图打的是同一个接口，逐页拉全量后必须与汇总口径完全相同
        $first = $this->getJson('/admin/asset/cards?page=1&page_size=2')->assertOk()->json('data');
        $this->assertSame(5, $first['total']);
        $this->assertSame(5, $first['summary']['count']);
        // JSON 里整额会退化成 int（800 而非 800.0），金额一律按容差比较
        $this->assertEqualsWithDelta(800, $first['summary']['original_value'], 0.001);
        $this->assertEqualsWithDelta(0, $first['summary']['accumulated_dep'], 0.001);
        $this->assertEqualsWithDelta(800, $first['summary']['net_value'], 0.001);

        $rows = [];
        for ($page = 1; $page <= $first['last_page']; $page++) {
            $data = $this->getJson("/admin/asset/cards?page={$page}&page_size=2")->assertOk()->json('data');
            // 每页返回的汇总都不随分页变化
            $this->assertSame($first['summary'], $data['summary']);
            $rows = array_merge($rows, $data['list']);
        }

        $this->assertCount($first['summary']['count'], $rows);
        $this->assertEqualsWithDelta($first['summary']['original_value'], array_sum(array_column($rows, 'original_value')), 0.001);
        $this->assertEqualsWithDelta($first['summary']['net_value'], array_sum(array_column($rows, 'net_value')), 0.001);
        $this->assertCount($first['summary']['count'], collect($rows)->pluck('asset_no')->unique());
    }

    public function test_ledger_filters_and_purchase_flow_filters_use_real_data(): void
    {
        $this->postJson('/admin/asset/purchases', $this->purchasePayload(['asset_name' => '注塑机', 'quantity' => 2, 'unit_price' => 100]))->assertOk();
        $this->postJson('/admin/asset/purchases', $this->purchasePayload([
            'asset_name' => '叉车',
            'purchase_date' => '2026-09-20',
            'quantity' => 1,
            'unit_price' => 500,
        ]))->assertOk();

        $forklift = $this->getJson('/admin/asset/cards?keyword=叉车')->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.asset_name', '叉车')
            ->assertJsonPath('data.summary.count', 1);
        $this->assertEqualsWithDelta(500, $forklift->json('data.summary.original_value'), 0.001);

        $october = $this->getJson('/admin/asset/cards?start_date=2026-10-01&end_date=2026-10-31')->assertOk()
            ->assertJsonPath('data.total', 2);
        $this->assertEqualsWithDelta(200, $october->json('data.summary.original_value'), 0.001);

        $this->getJson('/admin/asset/purchases?asset_name=叉车')->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.purchase_no', 'GZ-202609-0001');

        $this->getJson('/admin/asset/purchases?purchase_no=GZ-202610')->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->postJson('/admin/asset/purchases', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['purchase_date', 'asset_name', 'quantity', 'unit_price', 'use_months', 'residual_rate']);

        $this->postJson('/admin/asset/purchases', $this->purchasePayload(['quantity' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);

        $this->assertSame(0, AssetPurchase::count());
        $this->assertSame(0, AssetCard::count());
    }
}
