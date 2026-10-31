<?php

namespace Modules\Ledger\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Ledger\Models\Voucher;
use Modules\Ledger\Services\Generators\BillVoucherGenerator;
use Modules\Ledger\Services\Generators\DeliveryVoucherGenerator;
use Modules\Ledger\Services\Generators\ExpenseVoucherGenerator;
use Modules\Ledger\Services\Generators\PayVoucherGenerator;
use Modules\Ledger\Services\Generators\ReceiveVoucherGenerator;
use Modules\Ledger\Services\Generators\StockAdjustVoucherGenerator;

/**
 * 凭证生成服务：业务单据自动生成会计凭证的统一入口（QA-05 已打通）。
 *
 * 结构：本类只做**分发与落库编排**，每类单据的借贷口径写在
 * `Services/Generators/` 下的策略类里；策略只读单据、不写库，
 * 落库统一走 VoucherService::create()，以复用借贷平衡、末级科目校验、
 * 期间禁写与取号并发保护。
 *
 * 幂等：ledger_vouchers 上有 (source_type, source_id, source_seq) 唯一索引，
 * 生成前先查、命中即返回旧凭证；并发撞唯一键时由 VoucherService 兜底返回已存在凭证。
 * 因此「审核时自动生成 + 命令行批量补生成 + 人工重复点」都只会留一张凭证。
 *
 * 调用约定（三机协同，签名不得变更）：
 *   generateFromBusiness(string $billType, int $billId, array $context = []): ?Voucher
 * 调用方（业务侧审核动作）**必须自己 try/catch DomainException**：
 * 期间已月结、科目未初始化、单据金额不合规都属业务性失败，
 * 记警告日志即可，绝不能回滚审核（范式见 modules/Asset/Services/AssetPurchaseService.php:87-99）。
 * 未纳入本服务口径的单据类型（deposit/loan/asset_depreciation 等）返回 null 并记日志，
 * 保持机器 B 既有调用的「不报错」语义。
 */
class VoucherGenerateService
{
    /** 单据类型 → 生成策略 */
    private const STRATEGIES = [
        Voucher::SOURCE_DELIVERY => DeliveryVoucherGenerator::class,
        Voucher::SOURCE_RECEIVE => ReceiveVoucherGenerator::class,
        Voucher::SOURCE_PAY => PayVoucherGenerator::class,
        Voucher::SOURCE_EXPENSE => ExpenseVoucherGenerator::class,
        Voucher::SOURCE_STOCK_ADJUST => StockAdjustVoucherGenerator::class,
    ];

    /** @var array<string, BillVoucherGenerator> */
    private array $resolved = [];

    public function __construct(private VoucherService $voucherService) {}

    /**
     * 业务单据生成会计凭证。
     *
     * 一张单据可产出多张凭证（销售出库 = 收入凭证 + 成本凭证），
     * 返回值固定是**第 1 张**（成本凭证等后续张用 Voucher::listBySource 取）。
     *
     * @param  string  $billType  delivery/receive/pay/expense/stock_adjust；其它类型返回 null
     * @param  array<string, mixed>  $context  maker_id / maker_name / payment_method 等
     *
     * @throws \DomainException 单据不存在、未审核、期间已月结、科目缺失
     */
    public function generateFromBusiness(string $billType, int $billId, array $context = []): ?Voucher
    {
        if (! isset(self::STRATEGIES[$billType])) {
            // 保留占位语义：B 侧 deposit/loan/asset_depreciation 等调用不能报错
            Log::info('VoucherGenerateService.generateFromBusiness 未接单的单据类型，跳过', [
                'bill_type' => $billType,
                'bill_id' => $billId,
            ]);

            return null;
        }

        $payloads = $this->generator($billType)->payloads($billId, $context);
        if (empty($payloads)) {
            return null;
        }

        [$makerId, $makerName] = $this->maker($context);
        $first = null;

        foreach ($payloads as $index => $payload) {
            $seq = $index + 1;

            // 幂等：这张已生成过（重复审核 / 批量命令重跑 / 并发），不再新增
            $existing = Voucher::findBySource($billType, $billId, $seq);
            if ($existing) {
                $first ??= $existing->load('entries');

                continue;
            }

            $voucher = $this->voucherService->create([
                'voucher_date' => $payload['voucher_date'],
                'remark' => $payload['remark'],
                'entries' => $payload['entries'],
                'source_type' => $billType,
                'source_id' => $billId,
                'source_seq' => $seq,
            ], $makerId, $makerName);

            $first ??= $voucher;
        }

        Log::info('业务单据已生成凭证', [
            'bill_type' => $billType,
            'bill_id' => $billId,
            'count' => count($payloads),
            'first_no' => $first?->voucher_no,
        ]);

        return $first;
    }

    /**
     * 批量补生成：已审核但还没出凭证的单据全部生成凭证。
     *
     * 单张失败（期间已月结、科目缺失等）不影响其余单据，错误收进 errors 供命令展示。
     *
     * @param  array<int, string>  $billTypes
     * @param  array<string, mixed>  $context
     * @return array{generated:int, skipped:int, failed:int, details:array<int, array<string,mixed>>, errors:array<int, string>}
     */
    public function generateBatch(array $billTypes, array $context = [], bool $dryRun = false): array
    {
        $generated = 0;
        $skipped = 0;
        $failed = 0;
        $details = [];
        $errors = [];

        foreach ($billTypes as $billType) {
            if (! isset(self::STRATEGIES[$billType])) {
                continue;
            }

            foreach ($this->generator($billType)->pendingBills() as $bill) {
                $label = $billType.'#'.$bill['id'].($bill['bill_no'] ? ' '.$bill['bill_no'] : '');

                if ($dryRun) {
                    $skipped++;
                    $details[] = ['bill' => $label, 'status' => 'dry-run'];

                    continue;
                }

                try {
                    $voucher = $this->generateFromBusiness($billType, (int) $bill['id'], $context);
                    if ($voucher) {
                        $generated++;
                        $details[] = ['bill' => $label, 'status' => 'ok', 'voucher_no' => $voucher->voucher_no];
                    } else {
                        $skipped++;
                        $details[] = ['bill' => $label, 'status' => 'skip'];
                    }
                } catch (\DomainException $e) {
                    $failed++;
                    $errors[] = $label.'：'.$e->getMessage();
                    $details[] = ['bill' => $label, 'status' => 'fail', 'error' => $e->getMessage()];
                }
            }
        }

        return compact('generated', 'skipped', 'failed', 'details', 'errors');
    }

    /**
     * 各类型已审核单据数与其中尚未生成凭证的张数（结账检查③的真实统计）。
     *
     * @param  array<int, string>|null  $billTypes  缺省统计全部类型
     * @return array{audited:int, missing:int, types:array<string, array{audited:int, missing:int}>}
     */
    public function coverage(?array $billTypes = null): array
    {
        $types = [];
        $audited = 0;
        $missing = 0;

        foreach ($billTypes ?? array_keys(self::STRATEGIES) as $billType) {
            if (! isset(self::STRATEGIES[$billType])) {
                continue;
            }
            $one = $this->generator($billType)->coverage();
            $types[$billType] = $one;
            $audited += $one['audited'];
            $missing += $one['missing'];
        }

        return ['audited' => $audited, 'missing' => $missing, 'types' => $types];
    }

    /**
     * 某类单据中已生成过凭证的单据 id（业务侧自查与回归断言用）。
     *
     * @return array<int, int>
     */
    public function voucherizedBillIds(string $billType): array
    {
        if (! isset(self::STRATEGIES[$billType])) {
            return [];
        }

        return DB::table('ledger_vouchers')
            ->where('source_type', $billType)
            ->whereNotNull('source_id')
            ->distinct()
            ->pluck('source_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    private function generator(string $billType): BillVoucherGenerator
    {
        return $this->resolved[$billType] ??= app(self::STRATEGIES[$billType]);
    }

    /** 制单人：优先 context 显式传入，其次当前登录管理员，命令行兜底为「系统自动」 */
    private function maker(array $context): array
    {
        if (! empty($context['maker_id']) || ! empty($context['maker_name'])) {
            return [(int) ($context['maker_id'] ?? 0), (string) ($context['maker_name'] ?? '系统自动')];
        }

        $admin = auth('admin')->user();
        if ($admin) {
            return [(int) $admin->id, (string) ($admin->real_name ?? $admin->username ?? '管理员')];
        }

        return [0, '系统自动'];
    }

    /**
     * 事件监听器占位：业务单据审核事件触发时回调。
     *
     * 业务侧审核动作里直接 try 调用 generateFromBusiness 即可，无需注册监听；
     * 本方法保留给后续挂事件用（B 域存取款/借款等单据接入时复用）。
     */
    public function onBusinessVoucherRequested(string $billType, int $billId, array $context = []): void
    {
        try {
            $this->generateFromBusiness($billType, $billId, $context);
        } catch (\DomainException $e) {
            Log::warning('业务单据自动生成凭证失败（事件位）', [
                'bill_type' => $billType,
                'bill_id' => $billId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
