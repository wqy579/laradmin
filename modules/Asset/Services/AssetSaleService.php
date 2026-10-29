<?php

namespace Modules\Asset\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Models\AssetCard;
use Modules\Asset\Models\AssetSale;
use Modules\Ledger\Models\Subject;
use Modules\Ledger\Services\VoucherService;

/**
 * 资产变卖业务（D4）
 *
 * 硬规则收口在后端：
 *  1) 仅「在用」状态资产可变卖；已售/已处置/闲置 拒绝；
 *  2) 损益 = 变卖价 − 净值（正=盈利 营业外收入，负=亏损 营业外支出）；
 *  3) 保存后卡片状态置为已售（sold），净值与累计折旧定格（不再参与折旧）；
 *  4) 处置凭证（借 固定资产清理 / 贷 固定资产 + 银行存款 + 损益）可选，失败不回滚变卖。
 */
class AssetSaleService
{
    /** 固定资产原值 / 累计折旧 / 固定资产清理 / 银行存款 / 营业外收入 / 营业外支出 */
    private const SUBJECT_FIXED_ASSET = '1601';

    private const SUBJECT_ACCUM_DEP = '1602';

    private const SUBJECT_FIXED_CLEARING = '1606';

    private const SUBJECT_BANK = '1002';

    private const SUBJECT_GAIN = '6301';

    private const SUBJECT_LOSS = '6711';

    public function __construct(private VoucherService $voucherService) {}

    /**
     * 保存变卖单：取卡片当前净值，算损益，卡片状态置「已售」。
     *
     * @return array{sale: AssetSale, pnl: float, voucher_id: ?int, voucher_error: ?string}
     */
    public function store(array $data, int $makerId, string $makerName): array
    {
        $card = AssetCard::find((int) ($data['asset_card_id'] ?? 0));
        if (! $card) {
            throw ValidationException::withMessages(['asset_card_id' => '资产卡片不存在']);
        }
        if ($card->status !== AssetCard::STATUS_INUSE) {
            throw ValidationException::withMessages([
                'asset_card_id' => '仅「在用」状态的资产可变卖，当前状态：'.$card->statusLabel($card->status),
            ]);
        }

        $saleAmount = round((float) $data['sale_amount'], 2);
        $netValue = round((float) $card->net_value, 2);
        $gainLoss = round($saleAmount - $netValue, 2);
        $closingFee = round((float) ($data['closing_fee'] ?? 0), 2);

        $sale = DB::transaction(function () use ($data, $card, $saleAmount, $netValue, $gainLoss, $closingFee, $makerId) {
            $sale = AssetSale::create([
                'sale_no' => $this->nextSaleNo($data['sale_date']),
                'asset_card_id' => $card->id,
                'asset_no' => $card->asset_no,
                'asset_name' => $card->asset_name,
                'sale_date' => $data['sale_date'],
                'sale_amount' => $saleAmount,
                'net_value' => $netValue,
                'accumulated_dep' => (float) $card->accumulated_dep,
                'original_value' => (float) $card->original_value,
                'gain_loss' => $gainLoss,
                'closing_fee' => $closingFee,
                'buyer' => $data['buyer'],
                'handler' => $data['handler'] ?? null,
                'remark' => $data['remark'] ?? null,
                'created_by' => $makerId ?: null,
            ]);

            // 变卖后卡片置「已售」，净值清零（不再计提折旧）
            $card->forceFill([
                'status' => AssetCard::STATUS_SOLD,
                'net_value' => 0,
            ])->save();

            return $sale;
        });

        $voucherId = null;
        $voucherError = null;
        if (! empty($data['register_voucher'])) {
            try {
                $voucher = $this->voucherService->create([
                    'voucher_date' => $sale->sale_date->toDateString(),
                    'status' => 'audited',
                    'auto_audit' => true,
                    'remark' => '固定资产变卖：'.$sale->sale_no,
                    'entries' => $this->saleEntries($sale),
                ], $makerId, $makerName);

                $voucherId = $voucher->id;
                $sale->forceFill(['voucher_id' => $voucherId])->save();
            } catch (DomainException $e) {
                $voucherError = $e->getMessage();
            }
        }

        return [
            'sale' => $sale->fresh(),
            'pnl' => $gainLoss,
            'voucher_id' => $voucherId,
            'voucher_error' => $voucherError,
        ];
    }

    /** 变卖流水（分页） */
    public function sales(array $filters, int $page, int $pageSize): array
    {
        $query = AssetSale::query()
            ->when($filters['start_date'] ?? null, fn ($q, $v) => $q->whereDate('sale_date', '>=', $v))
            ->when($filters['end_date'] ?? null, fn ($q, $v) => $q->whereDate('sale_date', '<=', $v))
            ->when($filters['keyword'] ?? null, function ($q, $v) {
                $q->where(function ($sub) use ($v) {
                    $sub->where('sale_no', 'like', "%{$v}%")
                        ->orWhere('asset_name', 'like', "%{$v}%")
                        ->orWhere('buyer', 'like', "%{$v}%");
                });
            })
            ->orderByDesc('sale_date')->orderByDesc('id');

        $pager = $query->paginate($pageSize, ['*'], 'page', $page);

        return [
            'list' => $pager->items(),
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'last_page' => $pager->lastPage(),
        ];
    }

    /**
     * 处置分录（变卖，标准固定资产清理流程）：
     *  1) 转出：借 固定资产清理(净值) / 借 累计折旧 / 贷 固定资产(原值)
     *  2) 收款：借 银行存款(变卖价) / 贷 固定资产清理
     *  3) 清理费用：借 固定资产清理 / 贷 银行存款
     *  4) 损益结转：清理后净收益 = 变卖价 − 净值 − 清理费用，
     *     正 → 借 固定资产清理 / 贷 营业外收入；负 → 借 营业外支出 / 贷 固定资产清理。
     *
     * 借贷自动平衡：借 = (净值+累计折旧) + 变卖价 + 清理费 + 损益 = 原值 + 变卖价 + 清理费 + 损益 = 贷。
     *
     * @return array<int, array<string, mixed>>
     */
    private function saleEntries(AssetSale $sale): array
    {
        $summary = '变卖'.$sale->asset_name.'（'.$sale->sale_no.'）';
        $original = (float) $sale->original_value;
        $netValue = (float) $sale->net_value;
        $accDep = (float) $sale->accumulated_dep;
        $amount = (float) $sale->sale_amount;
        $closingFee = (float) $sale->closing_fee;

        $entries = [
            // 1) 转出固定资产：清理科目按账面净值入账，累计折旧对冲，原值贷出
            $this->entry(self::SUBJECT_FIXED_CLEARING, $summary, $netValue, 'debit'),
            $this->entry(self::SUBJECT_ACCUM_DEP, $summary, $accDep, 'debit'),
            $this->entry(self::SUBJECT_FIXED_ASSET, $summary, $original, 'credit'),
        ];

        // 2) 收款：借 银行存款 = 变卖价；贷 固定资产清理
        if ($amount > 0) {
            $entries[] = $this->entry(self::SUBJECT_BANK, $summary, $amount, 'debit');
            $entries[] = $this->entry(self::SUBJECT_FIXED_CLEARING, $summary, $amount, 'credit');
        }

        // 3) 清理费用：借 固定资产清理 / 贷 银行存款
        if ($closingFee > 0) {
            $entries[] = $this->entry(self::SUBJECT_FIXED_CLEARING, $summary, $closingFee, 'debit');
            $entries[] = $this->entry(self::SUBJECT_BANK, $summary, $closingFee, 'credit');
        }

        // 4) 清理损益：清理后净收益 = 变卖价 − 净值 − 清理费用
        $netGain = round($amount - $netValue - $closingFee, 2);
        if ($netGain > 0) {
            $entries[] = $this->entry(self::SUBJECT_FIXED_CLEARING, $summary, $netGain, 'debit');
            $entries[] = $this->entry(self::SUBJECT_GAIN, $summary, $netGain, 'credit');
        } elseif ($netGain < 0) {
            $loss = abs($netGain);
            $entries[] = $this->entry(self::SUBJECT_LOSS, $summary, $loss, 'debit');
            $entries[] = $this->entry(self::SUBJECT_FIXED_CLEARING, $summary, $loss, 'credit');
        }

        return $entries;
    }

    private function entry(string $code, string $summary, float $amount, string $direction): array
    {
        $subject = Subject::where('code', $code)->where('is_leaf', true)->first();
        if (! $subject) {
            throw new DomainException('未找到末级科目：'.$code.'，请先执行科目初始化');
        }

        return [
            'subject_id' => $subject->id,
            'summary' => $summary,
            'debit_amount' => $direction === 'debit' ? $amount : 0,
            'credit_amount' => $direction === 'credit' ? $amount : 0,
        ];
    }

    /** 单号 BM-yyyymm-####，按月续号 */
    private function nextSaleNo(string $date): string
    {
        $period = date('Ym', strtotime($date));
        $seq = AssetSale::where('sale_no', 'like', 'BM-'.$period.'-%')->count() + 1;

        return sprintf('BM-%s-%04d', $period, $seq);
    }
}
