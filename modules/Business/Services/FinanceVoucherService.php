<?php

namespace Modules\Business\Services;

use Modules\Ledger\Models\Voucher;

/**
 * 财务凭证查询视图（B1）—— 只读
 *
 * 凭证录入/审核统一走 ledger 视角（BUG-10），finance/voucher 为查询视图。
 * 从 A 机凭证体系（ledger）读取凭证+分录，聚合成凭证分组展示（含红冲分组）。
 */
class FinanceVoucherService
{
    public function list(array $filters = [], int $page = 1, int $pageSize = 30): array
    {
        $query = Voucher::query()
            ->with(['entries' => fn ($q) => $q->orderBy('line_no')])
            ->where('status', '!=', 'reversed');

        // 含红冲：默认排除红冲单（red_of_id 非空）
        if (empty($filters['include_red'])) {
            $query->whereNull('red_of_id');
        }

        // 凭证字号模糊
        if (! empty($filters['no'])) {
            $query->where('voucher_no', 'like', '%'.$filters['no'].'%');
        }

        // 日期区间
        if (! empty($filters['start_date'])) {
            $query->where('voucher_date', '>=', $filters['start_date']);
        }
        if (! empty($filters['end_date'])) {
            $query->where('voucher_date', '<=', $filters['end_date']);
        }

        // 科目：按分录科目编码前缀匹配
        if (! empty($filters['subject'])) {
            $query->whereHas('entries', fn ($q) => $q->where('subject_code', 'like', $filters['subject'].'%'));
        }

        // 制单人
        if (! empty($filters['employee'])) {
            $query->where('maker_name', $filters['employee']);
        }

        // 摘要关键字（分录摘要）
        if (! empty($filters['summary'])) {
            $query->whereHas('entries', fn ($q) => $q->where('summary', 'like', '%'.$filters['summary'].'%'));
        }

        // 状态（草稿/待审核/已审核；作废单默认已排除）
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        $query->orderByDesc('voucher_date')->orderByDesc('id');

        $paginator = $query->paginate($pageSize, ['*'], 'page', $page);

        $list = collect($paginator->items())->map(function (Voucher $voucher) {
            return $this->mapVoucher($voucher);
        })->all();

        return [
            'list' => $list,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    private function mapVoucher(Voucher $voucher): array
    {
        $entries = $voucher->entries->map(function ($entry) {
            return [
                'id' => $entry->id,
                'subject' => $entry->subject_code,
                'subject_name' => $entry->subject_name,
                'brand' => $entry->brand_id ? (string) $entry->brand_name : '',
                'brand_name' => (string) $entry->brand_name,
                'partner' => (string) $entry->aux_name,
                'abstract' => (string) $entry->summary,
                'debit' => (float) $entry->debit_amount,
                'credit' => (float) $entry->credit_amount,
            ];
        })->values()->all();

        return [
            'id' => $voucher->id,
            'no' => $voucher->voucher_no,
            'voucher_type' => '记',
            'date' => optional($voucher->voucher_date)->format('Y-m-d'),
            'period' => $voucher->period,
            'status' => $voucher->status,
            'status_label' => Voucher::statusLabel($voucher->status),
            'department' => '',
            'employee' => (string) $voucher->maker_name,
            'attachment_count' => (int) $voucher->attachments_count,
            'auto_audit' => (bool) $voucher->auto_audit,
            'remark' => (string) $voucher->remark,
            'red_of_id' => $voucher->red_of_id,
            'is_red' => (bool) $voucher->is_red,
            'total_debit' => (float) $voucher->total_debit,
            'total_credit' => (float) $voucher->total_credit,
            'items' => $entries,
        ];
    }
}
