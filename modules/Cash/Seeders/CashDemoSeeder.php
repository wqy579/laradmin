<?php

namespace Modules\Cash\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Cash\Models\CashAccount;
use Modules\Cash\Models\CashCardTxn;
use Modules\Cash\Models\CashFlow;
use Modules\Cash\Models\CashHandover;
use Modules\Cash\Models\CashHandoverItem;
use Modules\Cash\Models\CashIntegralGrant;
use Modules\Cash\Models\CashIntegralGrantItem;
use Modules\Cash\Models\CashIntegralRule;
use Modules\Cash\Models\CashIntegralVerify;
use Modules\Cash\Services\CashHandoverService;
use Modules\Cash\Services\CashIntegralService;
use Modules\Member\Models\Member;
use Modules\Member\Models\MemberLevel;
use Modules\Order\Models\Customer;
use Modules\Order\Models\SalesOrder;

/**
 * 出纳/积分演示数据（QA-02「11 页无数据」的数据面）。
 *
 * CashSeeder 只登记菜单与权限按钮，一行业务数据都不写，故 nginx 白名单修好后
 * 列表照样是空的。本类补一份最小演示数据，覆盖 D1 账户收支、刷卡记录、
 * D2 上交款、D5 积分规则/发放、D6 核销/查询、D8 工作台。
 *
 * 不在默认种子链路里（MigrationSmokeTest 基线：默认种子不产生业务行），
 * 需要时显式执行：
 *   php artisan db:seed --class="Modules\\Cash\\Seeders\\CashDemoSeeder"
 *
 * 三条口径与真实业务流同源，不是手抄一份假数：
 *  1) 上交款「明细合计 == 表头金额」由 CashHandoverService::create 的强校验把关，
 *     已确认单走 confirm()，收入流水与订单回款都由服务生成；
 *  2) 积分发放/核销走 CashIntegralService 的 save→submit→audit 全流程，
 *     members.points 与 member_consume_logs 由服务写入，核销额天然 ≤ 可用余额；
 *  3) 幂等：每块数据都有稳定抓手（单号前缀 / remark / member_no），重跑先删再建，
 *     演示会员积分先归零再由服务重算，不会二次累加。
 *
 * 前置数据缺失时对应区块跳过并告警，不抛异常——演示数据不该让种子失败。
 */
class CashDemoSeeder extends Seeder
{
    /** 演示数据标记：写在 remark 上，兼作幂等删除的抓手 */
    private const DEMO = '演示数据（QA-02）';

    private const FLOW_PREFIX = 'DEMO-LS-';

    private const CARD_PREFIX = 'DEMO-SK-';

    /** 演示会员积分基准：重跑时先归零，再由发放/核销重算 */
    private const MEMBER_BASE_POINTS = 0;

    private const MEMBER_NOS = ['HYDEMO00000001', 'HYDEMO00000002'];

    public function run(): void
    {
        $adminId = $this->operatorId();

        $accounts = $this->seedAccounts();
        $this->seedFlows($accounts, $adminId);
        $this->seedCardTxns($adminId);
        $this->seedHandovers($accounts, $adminId);
        $this->seedIntegral($adminId);

        $this->command?->info('CashDemoSeeder: 出纳/积分演示数据已生成。');
    }

    /**
     * 经办人：优先 admin，没有就退到任一启用用户。
     *
     * 默认种子链路只保证「存在超级管理员用户」，不保证用户名叫 admin；
     * 只认 admin 会让 salesman_name 落成 NULL，刷卡页的业务员筛选下拉因此为空
     * （CashCardController::options 从流水行 distinct 取业务员）。
     */
    private function operatorId(): ?int
    {
        $row = DB::table('auth_user')->where('username', 'admin')->first(['id', 'real_name', 'username'])
            ?: DB::table('auth_user')->where('status', 1)->orderBy('id')->first(['id', 'real_name', 'username']);

        return $row ? (int) $row->id : null;
    }

    /** @return array{cash: CashAccount, bank: CashAccount} */
    private function seedAccounts(): array
    {
        $cash = CashAccount::firstOrCreate(
            ['name' => '演示·现金主账户'],
            [
                'type' => CashAccount::TYPE_CASH,
                'opening_balance' => 20000,
                'status' => CashAccount::STATUS_ENABLED,
                'sort' => 90,
            ]
        );
        $bank = CashAccount::firstOrCreate(
            ['name' => '演示·工行基本户'],
            [
                'type' => CashAccount::TYPE_BANK,
                'account_no' => '6222020200112233445',
                'opening_balance' => 358000,
                'status' => CashAccount::STATUS_ENABLED,
                'sort' => 91,
            ]
        );
        // 停用账户：让「仅启用账户」下拉有真实区分度
        CashAccount::firstOrCreate(
            ['name' => '演示·已停用备用金'],
            [
                'type' => CashAccount::TYPE_CASH,
                'opening_balance' => 0,
                'status' => CashAccount::STATUS_DISABLED,
                'sort' => 92,
            ]
        );

        return ['cash' => $cash, 'bank' => $bank];
    }

    /**
     * 收支流水：近 10 天穿插收入/支出，且当日必须有行——
     * 工作台 summary/trend 按今天与近 N 日取数（CashDashboardService::summary
     * 用 whereDate(flow_date, today)），只填历史日期工作台照样是零。
     *
     * flow_no 带 DEMO 前缀，不会与上交款确认时生成的流水号（= handover_no）
     * 撞 cash_flow 的 unique(account_id, flow_no)。
     */
    private function seedFlows(array $accounts, ?int $adminId): void
    {
        if (! Schema::hasTable('cash_flow')) {
            return;
        }
        CashFlow::query()->where('flow_no', 'like', self::FLOW_PREFIX.'%')->delete();

        $rows = [
            [0, 'cash', 'income', 4860.00, '门店货款收入'],
            [0, 'bank', 'income', 26800.00, '客户转账回款'],
            [0, 'cash', 'expense', 620.00, '包装耗材'],
            [1, 'cash', 'income', 3980.50, '门店货款收入'],
            [1, 'bank', 'expense', 15000.00, '采购付款'],
            [2, 'bank', 'income', 18600.00, '客户转账回款'],
            [3, 'cash', 'expense', 1240.00, '搬运临时用工'],
            [4, 'bank', 'income', 9600.00, '预收款'],
            [6, 'cash', 'income', 5320.00, '门店货款收入'],
            [8, 'bank', 'expense', 8800.00, '车辆加油与路桥费'],
            [10, 'cash', 'income', 4210.00, '门店货款收入'],
        ];

        foreach ($rows as $idx => [$ago, $which, $direction, $amount, $summary]) {
            $date = now()->subDays($ago)->toDateString();

            CashFlow::create([
                'account_id' => $accounts[$which]->id,
                'flow_no' => self::FLOW_PREFIX.$date.'-'.str_pad((string) ($idx + 1), 3, '0', STR_PAD_LEFT),
                'flow_date' => $date,
                'direction' => $direction,
                'summary' => $summary.'（'.self::DEMO.'）',
                'amount' => $amount,
                'related_type' => null,
                'related_id' => null,
                'created_by' => $adminId,
            ]);
        }
    }

    /**
     * 刷卡记录：当日成功 + 当日退款 + 当日失败 + 隔日成功。
     *
     * 退款行必须落在今天且 status=refund、金额为负——CashCardTxn::refundable()
     * 的判据是「成功且当日」，造隔日退款行是假数据；工作台只对 success 求和，
     * 退款因此天然被剔除。卡号写全量，脱敏由 CashCardTxn::maskCardNo 在输出层做。
     */
    private function seedCardTxns(?int $adminId): void
    {
        if (! Schema::hasTable('cash_card_txn')) {
            return;
        }
        CashCardTxn::query()->where('serial_no', 'like', self::CARD_PREFIX.'%')->delete();

        $customers = Customer::query()->orderBy('id')->limit(3)->get();
        $salesman = $adminId === null ? null : (string) (DB::table('auth_user')->where('id', $adminId)->value('real_name')
            ?: DB::table('auth_user')->where('id', $adminId)->value('username'));

        $rows = [
            ['min' => 30, 'days' => 0, 'card' => '6222021234567890', 'amount' => 2860.00, 'fee' => 17.16, 'status' => 'success', 'who' => 0],
            ['min' => 95, 'days' => 0, 'card' => '6222029876543210', 'amount' => 1580.00, 'fee' => 9.48, 'status' => 'success', 'who' => 1],
            ['min' => 140, 'days' => 0, 'card' => '6222021234567890', 'amount' => -480.00, 'fee' => -2.88, 'status' => 'refund', 'who' => 0],
            ['min' => 210, 'days' => 0, 'card' => '6222025555666677', 'amount' => 7320.00, 'fee' => 43.92, 'status' => 'success', 'who' => 2],
            ['min' => 260, 'days' => 0, 'card' => '6222028888999900', 'amount' => 960.00, 'fee' => 0, 'status' => 'fail', 'who' => 1],
            ['min' => 600, 'days' => 1, 'card' => '6222021234567890', 'amount' => 4130.00, 'fee' => 24.78, 'status' => 'success', 'who' => 0],
            ['min' => 1500, 'days' => 2, 'card' => '6222029876543210', 'amount' => 2690.00, 'fee' => 16.14, 'status' => 'success', 'who' => 2],
        ];

        foreach ($rows as $idx => $row) {
            $time = now()->subDays($row['days'])->subMinutes($row['min']);
            $customer = $customers->get($row['who']);
            $isRefund = $row['status'] === CashCardTxn::STATUS_REFUND;

            CashCardTxn::create([
                'serial_no' => self::CARD_PREFIX.$time->format('Ymd').'-'.str_pad((string) ($idx + 1), 3, '0', STR_PAD_LEFT),
                'txn_time' => $time->format('Y-m-d H:i:s'),
                'customer_id' => $customer?->id,
                'customer_name' => $this->snap($customer?->name, 120),
                'salesman_id' => $adminId,
                'salesman_name' => $salesman,
                'card_type' => 'debit',
                'card_no' => $row['card'],
                'card_last4' => substr($row['card'], -4),
                'amount' => $row['amount'],
                'fee' => $row['fee'],
                'status' => $row['status'],
                'rel_no' => null,
                'refunded_by' => $isRefund ? $adminId : null,
                'refunded_at' => $isRefund ? $time->format('Y-m-d H:i:s') : null,
            ]);
        }
    }

    /**
     * 上交款：一张已确认（走 confirm，收入流水与订单回款由服务生成）+
     * 一张待确认（工作台「待确认上交款」卡片读的就是 pending）。
     *
     * 明细只关联真实未收讫订单（判据同 CashHandoverController::orders 的右栏）。
     * 一条都没有时整块跳过，不去伪造订单：造出来的订单会污染 Order 域的报表与
     * 对账，那种空是环境没业务数据，本就该空。
     */
    private function seedHandovers(array $accounts, ?int $adminId): void
    {
        $this->deleteHandovers();

        $orders = SalesOrder::query()
            ->whereNotIn('status', ['cancelled', '已红冲', '已取消'])
            ->whereRaw('total_amount - paid_amount > 0.01')
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($orders->count() < 2) {
            $this->command?->warn('CashDemoSeeder: 未收讫销售订单不足 2 张，跳过上交款演示数据（可先跑 FlowDemoSeeder）。');

            return;
        }

        $service = app(CashHandoverService::class);
        $salesmanName = $this->snap($orders[0]->salesman_name, 60) ?: '演示业务员';

        // 已确认单：表头金额取明细合计，与服务的强校验同源
        $items = [];
        foreach ($orders as $idx => $order) {
            $unpaid = round((float) $order->total_amount - (float) $order->paid_amount, 2);
            $items[] = $this->handoverItem($order, $idx === 0 ? $unpaid : min(1000.00, $unpaid));
        }

        $confirmed = $service->create([
            'handover_date' => now()->subDay()->toDateString(),
            'salesman_id' => $adminId,
            'salesman_name' => $salesmanName,
            'account_id' => $accounts['bank']->id,
            'amount' => round(array_sum(array_column($items, 'handover_amount')), 2),
            'remark' => self::DEMO,
        ], $items, $adminId);
        $service->confirm($confirmed->id, $adminId);

        // 待确认单：单明细，金额取该单未收部分（上限 1800）
        $pendingOrder = $orders[1];
        $pendingAmount = round((float) $pendingOrder->total_amount - (float) $pendingOrder->paid_amount, 2);

        $service->create([
            'handover_date' => now()->toDateString(),
            'salesman_id' => $adminId,
            'salesman_name' => $salesmanName,
            'account_id' => $accounts['cash']->id,
            'amount' => $pendingAmount,
            'remark' => self::DEMO,
        ], [$this->handoverItem($pendingOrder, $pendingAmount)], $adminId);
    }

    /** @return array<string, mixed> */
    private function handoverItem(SalesOrder $order, float $handoverAmount): array
    {
        return [
            'sales_order_id' => $order->id,
            'order_no' => $order->order_no,
            'customer_id' => $order->customer_id,
            'customer_name' => $this->snap($order->customer?->name, 120),
            'order_date' => optional($order->order_date)->toDateString() ?? now()->toDateString(),
            'order_amount' => round((float) $order->total_amount, 2),
            'handover_amount' => round($handoverAmount, 2),
        ];
    }

    /**
     * 积分：规则 → 演示会员 → 发放（一张已审核 + 一张待审核）→ 核销（已审核）。
     *
     * 全程走服务：members.points 与 member_consume_logs 由服务写，口径与真实业务
     * 完全一致；核销额取「已入账余额的一半」，服务里那条「会员可用积分不足」判据
     * （CashIntegralService::verifyAudit）因此天然成立，不是绕过它插数。
     *
     * 积分查询（cash/integral/ledger/members）只认 member_consume_logs 里有积分
     * 变动的会员，所以必须真跑一遍发放——光插 members 行查不出东西。
     */
    private function seedIntegral(?int $adminId): void
    {
        if (Schema::hasTable('cash_integral_rule')) {
            CashIntegralRule::query()->where('remark', self::DEMO)->delete();

            foreach ([
                ['演示·消费 1 元积 1 分', 1, CashIntegralRule::STATUS_ENABLED],
                ['演示·消费 1 元积 2 分（活动）', 2, CashIntegralRule::STATUS_ENABLED],
                ['演示·已停用规则', 0.5, CashIntegralRule::STATUS_DISABLED],
            ] as [$name, $ratio, $status]) {
                CashIntegralRule::create([
                    'rule_name' => $name,
                    'ratio' => $ratio,
                    'effective_date' => now()->startOfMonth()->toDateString(),
                    'status' => $status,
                    'remark' => self::DEMO,
                    'created_by' => $adminId,
                ]);
            }
        }

        $members = $this->seedMembers();
        if ($members === []) {
            $this->command?->warn('CashDemoSeeder: 缺 members / member_levels，跳过积分发放与核销演示数据。');

            return;
        }

        $this->deleteIntegralDocuments();

        $service = app(CashIntegralService::class);
        $operatorId = $adminId ?? (int) $members[0]->id;

        // 首单走完整流程到已审核（真正入账），次单停在待审核（列表状态多样性）
        $batches = [
            ['type' => CashIntegralGrant::TYPE_DAILY, 'days' => 2, 'points' => [200, 120], 'audit' => true],
            ['type' => CashIntegralGrant::TYPE_ACTIVITY, 'days' => 1, 'points' => [300, 80], 'audit' => false],
        ];
        foreach ($batches as $batch) {
            $items = [];
            foreach ($members as $i => $member) {
                $items[] = [
                    'member_id' => $member->id,
                    'points' => $batch['points'][$i],
                    'remark' => self::DEMO,
                ];
            }

            $grant = $service->grantSave([
                'grant_date' => now()->subDays($batch['days'])->toDateString(),
                'grant_type' => $batch['type'],
                'remark' => self::DEMO,
                'items' => $items,
            ], null, $operatorId);

            $service->grantSubmit($grant);

            if ($batch['audit']) {
                $service->grantAudit($grant->id, $operatorId);
            }
        }

        // 核销：只核第一个会员，额度 = 已入账余额的一半，恒 ≤ 可用余额
        $target = $members[0]->fresh();
        $use = max(1, (int) floor((int) $target->points / 2));

        $verify = $service->verifySave([
            'verify_date' => now()->toDateString(),
            'member_id' => $target->id,
            'points' => $use,
            'verify_type' => CashIntegralVerify::TYPE_CONSUME,
            'remark' => self::DEMO,
        ], null, $operatorId);
        $service->verifySubmit($verify);
        $service->verifyAudit($verify->id, $operatorId);

        $this->command?->info("CashDemoSeeder: 演示会员 {$target->member_no} 入账 {$target->points} 分，核销 {$use} 分。");
    }

    /**
     * 演示会员：固定 member_no，重跑时积分先归零再由发放重算。
     *
     * @return array<int, Member>
     */
    private function seedMembers(): array
    {
        if (! Schema::hasTable('members') || ! Schema::hasTable('member_levels')) {
            return [];
        }

        $levelId = (int) MemberLevel::query()->orderBy('id')->value('id') ?: 1;
        $customers = Customer::query()->orderBy('id')->limit(2)->get();

        $members = [];
        foreach (self::MEMBER_NOS as $i => $no) {
            $customer = $customers->get($i);

            Member::query()->firstOrCreate(
                ['member_no' => $no],
                [
                    'customer_id' => $customer?->id,
                    'customer_name' => $this->snap($customer?->name, 200),
                    'name' => '演示会员'.($i + 1),
                    'phone' => '1390000'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                    'level_id' => $levelId,
                    'points' => self::MEMBER_BASE_POINTS,
                    'balance' => 0,
                    'register_date' => now()->startOfMonth()->toDateString(),
                    'status' => Member::STATUS_ACTIVE,
                    'remark' => self::DEMO,
                ]
            );

            // 重跑：积分归零后由发放/核销重算，避免二次累加
            Member::query()->where('member_no', $no)->update(['points' => self::MEMBER_BASE_POINTS]);
            $members[] = Member::query()->where('member_no', $no)->firstOrFail();
        }

        return $members;
    }

    // ==================== 辅助 ====================

    /** 客户名截到目标列宽：真实客户名可能长于快照列，别让它把种子崩掉 */
    private function snap(?string $value, int $limit): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }

    // ==================== 幂等清理 ====================

    /**
     * 删演示上交款，并回收确认时由服务生成的收入流水（flow_no == handover_no）
     * 与订单已收款回写。
     *
     * 必须退已收款：CashHandoverService::confirm() 会把上交额累加进
     * sales_orders.paid_amount，而本类挑明细的判据正是「未收部分 > 0」。
     * 不退的话第二次重跑就挑不到 2 张未收讫订单，上交款区块会静默跳过——
     * 演示数据在自己手上越跑越少，等于不可重入。
     *
     * 只退 remark 带 DEMO 标记的单，真实上交款一分不动。
     */
    private function deleteHandovers(): void
    {
        $handovers = CashHandover::query()->where('remark', self::DEMO)->get(['id', 'handover_no']);
        if ($handovers->isEmpty()) {
            return;
        }

        $ids = $handovers->pluck('id')->all();

        if (Schema::hasTable('cash_flow')) {
            CashFlow::query()->whereIn('flow_no', $handovers->pluck('handover_no')->all())->delete();
        }

        $items = CashHandoverItem::query()->whereIn('handover_id', $ids)
            ->whereNotNull('sales_order_id')->get(['sales_order_id', 'handover_amount']);
        CashHandoverItem::query()->whereIn('handover_id', $ids)->delete();
        CashHandover::query()->whereIn('id', $ids)->delete();

        $this->unapplyOrderPayments($items);
    }

    /**
     * 撤销已收款回写：金额退回后，收款状态/订单状态交给服务同一口径重算，
     * 本类不另写一套「已收款/部分收款」判定。
     *
     * @param  Collection<int, CashHandoverItem>  $items
     */
    private function unapplyOrderPayments($items): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $service = app(CashHandoverService::class);
        $byOrder = $items->groupBy('sales_order_id')->map
            ->sum('handover_amount');

        foreach (SalesOrder::query()->lockForUpdate()
            ->whereIn('id', $byOrder->keys())->get() as $order) {
            $back = round((float) $order->paid_amount - (float) $byOrder[$order->id], 2);
            $order->paid_amount = max(0, $back);
            $service->syncPaymentStatus($order);

            // 服务只往前推状态，退款时要手动摘掉「已收款」这顶帽子
            if ($order->status === '已收款' && $order->payment_status !== '已收款') {
                $order->status = 'approved';
            }

            $order->save();
        }
    }

    /**
     * 删演示积分单据与对应台账行。
     *
     * 台账（member_consume_logs）只删演示会员的积分类日志：发放/核销日志的 remark
     * 来自明细/单据，都带 DEMO 标记。真实会员的账一条都不碰。
     */
    private function deleteIntegralDocuments(): void
    {
        $memberIds = Member::query()->whereIn('member_no', self::MEMBER_NOS)->pluck('id')->all();

        if ($memberIds !== [] && Schema::hasTable('member_consume_logs')) {
            DB::table('member_consume_logs')
                ->whereIn('member_id', $memberIds)
                ->whereIn('consume_type', ['points_grant', 'points_use'])
                ->where('remark', 'like', '%'.self::DEMO.'%')
                ->delete();
        }

        $grantIds = CashIntegralGrant::query()->where('remark', self::DEMO)->pluck('id')->all();
        if ($grantIds !== []) {
            CashIntegralGrantItem::query()->whereIn('grant_id', $grantIds)->delete();
            CashIntegralGrant::query()->whereIn('id', $grantIds)->delete();
        }

        CashIntegralVerify::query()->where('remark', self::DEMO)->delete();
    }
}
