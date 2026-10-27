<?php

namespace Modules\Business\Services;

use Carbon\CarbonInterface;
use Modules\Ledger\Models\VoucherEntry;

/**
 * 财务余额查询（B3）—— 客户 / 品牌 / 辅助单位 三类余额查询视图
 *
 * 只读透出 A 机 ledger 分录，按维度（customer=往来单位 / brand=品牌 / aux=辅助单位）
 * 聚合各科目余额。预收/应收 两列语义：receivable=该科目借方发生合计、prepaid=贷方发生合计，
 * balance=按科目余额方向的净额。本服务不改写任何 ledger 数据。
 */
class FinanceBalanceService
{
    /** 维度 → 取值口径：customer 按 aux_type+aux_id；brand 按 brand_id；aux 按 aux_type+aux_id */
    private const AUX_KIND_LABEL = [
        'customer' => '客户',
        'supplier' => '供应商',
        'employee' => '业务员',
    ];

    public function list(string $dimension, array $filters = []): array
    {
        $rows = VoucherEntry::query()
            ->join('ledger_vouchers', 'ledger_voucher_entries.voucher_id', '=', 'ledger_vouchers.id')
            ->where('ledger_vouchers.status', 'audited')
            ->whereNull('ledger_vouchers.red_of_id')
            ->get([
                'ledger_voucher_entries.id', 'ledger_voucher_entries.aux_type', 'ledger_voucher_entries.aux_id',
                'ledger_voucher_entries.aux_name', 'ledger_voucher_entries.brand_id', 'ledger_voucher_entries.brand_name',
                'ledger_voucher_entries.subject_id', 'ledger_voucher_entries.subject_code', 'ledger_voucher_entries.subject_name',
                'ledger_voucher_entries.debit_amount', 'ledger_voucher_entries.credit_amount',
                'ledger_vouchers.voucher_date',
            ]);

        $rows = $rows->filter(fn ($r) => $this->hasDimension($r, $dimension))->values();

        // 按维度分组聚合
        $groups = [];
        foreach ($rows as $r) {
            $key = $this->groupKey($r, $dimension);
            if (! $key) {
                continue;
            }
            [$unitKey, $unitName, $category, $unitType] = $key;
            $subject = $r->subject_code ?: $r->subject_name ?: '';
            $bKey = $unitKey.'|'.$subject;
            $groups[$bKey] = $groups[$bKey] ?? [
                'category' => $category,
                'unit' => $unitName,
                'unitType' => $unitType,
                'brand' => $dimension === 'brand' ? $r->brand_name : '',
                'subject' => $r->subject_name,
                'debit' => 0.0,
                'credit' => 0.0,
                'date' => $dimension === 'aux' ? $this->dateString($r->voucher_date) : null,
            ];
            $groups[$bKey]['debit'] += (float) $r->debit_amount;
            $groups[$bKey]['credit'] += (float) $r->credit_amount;
            if ($dimension === 'aux') {
                $groups[$bKey]['date'] = max($groups[$bKey]['date'] ?? '', $this->dateString($r->voucher_date));
            }
        }

        $list = [];
        foreach ($groups as $g) {
            $direction = $this->subjectDirection($g['subject']) === 'credit' ? -1 : 1;
            $net = round($g['debit'] - $g['credit'], 2);
            $list[] = [
                'unitType' => $g['unitType'],
                'category' => $g['category'],
                'unit' => $g['unit'],
                'brand' => $g['brand'],
                'subject' => $g['subject'],
                'prepaid' => round($direction === -1 ? $g['credit'] : $g['debit'], 2),
                'receivable' => round($direction === -1 ? $g['debit'] : $g['credit'], 2),
                'balance' => round($direction * $net, 2),
                'total' => round($direction * $net, 2),
                'date' => $g['date'] ?: '',
            ];
        }

        // 应用筛选
        $list = $this->applyFilters($list, $dimension, $filters);

        return $list;
    }

    private function hasDimension($r, string $dimension): bool
    {
        if ($dimension === 'brand') {
            return ! empty($r->brand_id) && ! empty($r->brand_name);
        }
        if ($dimension === 'aux') {
            return ! empty($r->aux_id);
        }

        // customer：客户/供应商/业务员 往来单位
        return ! empty($r->aux_id) && in_array($r->aux_type, ['customer', 'supplier', 'employee'], true);
    }

    private function groupKey($r, string $dimension): array
    {
        if ($dimension === 'brand') {
            return [
                'brand:'.$r->brand_id.':'.($r->aux_id ?: $r->aux_name),
                $r->aux_name ?: ('品牌#'.$r->brand_name),
                $this->categoryLabel($r->aux_type),
                $this->unitTypeLabel($r->aux_type),
            ];
        }
        if ($dimension === 'aux') {
            return [
                'aux:'.$r->aux_type.':'.$r->aux_id,
                $r->aux_name,
                $this->auxLabel($r->aux_type),
                $this->auxLabel($r->aux_type),
            ];
        }

        // customer
        return [
            'unit:'.$r->aux_type.':'.$r->aux_id,
            $r->aux_name,
            $this->categoryLabel($r->aux_type),
            $this->unitTypeLabel($r->aux_type),
        ];
    }

    private function categoryLabel(?string $auxType): string
    {
        return self::AUX_KIND_LABEL[$auxType] ?? '其他';
    }

    private function unitTypeLabel(?string $auxType): string
    {
        return self::AUX_KIND_LABEL[$auxType] ?? '其他';
    }

    private function auxLabel(?string $auxType): string
    {
        $map = [
            'store' => '门店',
            'department' => '部门',
            'project' => '其他',
        ];

        return $map[$auxType] ?? ($auxType ?: '其他');
    }

    private function subjectDirection(string $subjectName): string
    {
        // 常见贷方余额科目：应付/预收/负债/权益/收入
        if (in_array($subjectName, ['应付账款', '预收账款', '其他应付款', '应交税费', '主营业务收入'], true)) {
            return 'credit';
        }

        return 'debit';
    }

    private function dateString($date): string
    {
        if (! $date) {
            return '';
        }

        return $date instanceof CarbonInterface ? $date->format('Y-m-d') : (string) $date;
    }

    private function applyFilters(array $list, string $dimension, array $filters): array
    {
        $keyword = strtolower(trim($filters['keyword'] ?? ''));
        $subject = trim($filters['subject'] ?? '');
        $unitType = trim($filters['unitType'] ?? '');
        $brand = trim($filters['brand'] ?? '');

        return array_values(array_filter($list, function ($r) use ($dimension, $keyword, $subject, $unitType, $brand) {
            if ($keyword && ! str_contains(strtolower($r['unit'].($dimension === 'brand' ? $r['subject'] : '')), $keyword)) {
                return false;
            }
            if ($subject && $r['subject'] !== $subject) {
                return false;
            }
            if ($unitType && $r['unitType'] !== $unitType) {
                return false;
            }
            if ($dimension === 'brand' && $brand && $r['brand'] !== $brand) {
                return false;
            }

            return true;
        }));
    }
}
