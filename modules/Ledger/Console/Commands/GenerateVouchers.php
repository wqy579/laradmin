<?php

namespace Modules\Ledger\Console\Commands;

use Illuminate\Console\Command;
use Modules\Ledger\Models\Voucher;
use Modules\Ledger\Services\VoucherGenerateService;

/**
 * 业务单据补生成凭证（QA-05）。
 *
 * 审核动作已内联触发生成，但历史存量单据、以及审核时因故失败（期间未开、
 * 科目未初始化）的单据需要一个补跑的口子，故保留命令行批量入口。
 *
 * 用法：
 *   php artisan ledger:generate-vouchers --dry-run              # 只看待补清单，不落库
 *   php artisan ledger:generate-vouchers                        # 全部类型补生成
 *   php artisan ledger:generate-vouchers --type=delivery,pay    # 指定类型（逗号分隔）
 *
 * 生成的是**草稿**凭证：让会计在凭证列表复核后再审核入账，
 * 不让批量脚本直接把单据塞进报表（报表只统计已审核凭证）。
 * 幂等由 ledger_vouchers 的 (source_type, source_id, source_seq) 唯一索引保证，
 * 重复跑只补漏、不会长出重复凭证。
 */
class GenerateVouchers extends Command
{
    protected $signature = 'ledger:generate-vouchers
        {--type= : 只处理指定单据类型，逗号分隔：delivery,receive,pay,expense,stock_adjust}
        {--dry-run : 只列出待生成的单据，不写库}';

    protected $description = '业务单据（销售出库/收款/付款/费用/库存调整）批量补生成记账凭证';

    public function handle(VoucherGenerateService $service): int
    {
        $types = $this->types();
        if ($types === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        // 先给覆盖率总览，便于判断本次要补多少
        $coverage = $service->coverage($types);
        $this->info('已审核单据 '.$coverage['audited'].' 张，其中未生成凭证 '.$coverage['missing'].' 张');
        foreach ($coverage['types'] as $type => $one) {
            $this->line(sprintf(
                '  %-13s 已审核 %4d  未生成 %4d',
                $type,
                $one['audited'],
                $one['missing']
            ));
        }

        $result = $service->generateBatch($types, [], $dryRun);

        if ($dryRun) {
            $this->warn('[dry-run] 待生成 '.$result['skipped'].' 张，未写库');
            foreach ($result['details'] as $detail) {
                $this->line('  '.$detail['bill']);
            }

            return self::SUCCESS;
        }

        foreach ($result['details'] as $detail) {
            if ($detail['status'] === 'ok') {
                $this->line('  ✓ '.$detail['bill'].' → '.$detail['voucher_no']);
            }
        }
        foreach ($result['errors'] as $error) {
            $this->error('  ✗ '.$error);
        }

        $this->info('完成：生成 '.$result['generated'].' 张，跳过 '.$result['skipped'].' 张，失败 '.$result['failed'].' 张');
        if ($result['generated'] > 0) {
            $this->warn('生成的凭证为草稿状态，请在「记账凭证」里复核后审核入账');
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 解析 --type，非法值直接报错退出（不静默忽略，否则使用者以为跑了却没跑）。
     *
     * @return array<int, string>|null
     */
    private function types(): ?array
    {
        $raw = trim((string) $this->option('type'));
        if ($raw === '') {
            return Voucher::SOURCE_TYPES;
        }

        $types = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $unknown = array_diff($types, Voucher::SOURCE_TYPES);
        if (! empty($unknown)) {
            $this->error('不支持的单据类型：'.implode(', ', $unknown).'（可选：'.implode(', ', Voucher::SOURCE_TYPES).'）');

            return null;
        }

        return $types;
    }
}
