<?php

namespace Tests\Ledger\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ledger\Models\OpeningBalance;
use Modules\Ledger\Models\Subject;
use Tests\TestCase;

/**
 * QA-10：账套开账状态接口 GET /admin/ledger/periods/boot-status
 *
 * 总账各页顶部的「账套初始化中」提示条以此接口为准：期初余额尚未录入时
 * opened=false（显示提示条），录入任意一行期初即视为已开账（opened=true，自动隐藏）。
 */
class BookInitStatusTest extends TestCase
{
    use RefreshDatabase;

    /** 迁移已预置科目种子，这里取现成的末级科目，避免撞 code 唯一键 */
    private function subject(): Subject
    {
        return Subject::query()->orderBy('code')->firstOrFail();
    }

    public function test_boot_status_reports_not_opened_without_opening_balances(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/admin/ledger/periods/boot-status')
            ->assertOk()
            ->assertJsonPath('data.opened', false);
    }

    public function test_boot_status_reports_opened_after_opening_balance_saved(): void
    {
        $this->actingAsAdmin();

        OpeningBalance::create([
            'subject_id' => $this->subject()->id,
            'period' => date('Y-m'),
            'opening_debit' => 1000.00,
            'opening_credit' => 0,
        ]);

        $this->getJson('/admin/ledger/periods/boot-status')
            ->assertOk()
            ->assertJsonPath('data.opened', true);
    }
}
