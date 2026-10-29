<?php

namespace Modules\Cash\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Cash\Models\CashCardTxn;

/**
 * 刷卡收款记录控制器（P0-10，cash/card-txns 前缀）
 *
 * 两条硬规则在后端收口：卡号一律脱敏输出（列表与导出都不吐完整卡号）、
 * 退款仅允许「成功且当日」，非当日/非成功一律 422。
 */
class CashCardController extends Controller
{
    use ResponseTrait;

    private const EXPORT_LIMIT = 50000;

    /** 筛选下拉选项：客户/业务员取刷卡记录中实际出现过的，不与 B 域接口耦合 */
    public function options()
    {
        $distinct = function (string $idCol, string $nameCol) {
            $rows = CashCardTxn::query()
                ->whereNotNull($nameCol)
                ->groupBy($idCol, $nameCol)
                ->orderBy($idCol)
                ->get([$idCol, $nameCol]);

            return $rows->map(fn ($r) => ['id' => (int) $r->{$idCol}, 'name' => (string) $r->{$nameCol}])->values();
        };

        return $this->success([
            'customers' => $distinct('customer_id', 'customer_name'),
            'salesmen' => $distinct('salesman_id', 'salesman_name'),
        ]);
    }

    public function index(Request $request)
    {
        $q = $this->filtered($request);
        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(200, max(1, (int) $request->input('page_size', 20)));

        $pager = $q->orderByDesc('txn_time')->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);

        $rows = collect($pager->items())->map(fn (CashCardTxn $t) => $this->transform($t))->values();

        return $this->success([
            'list' => $rows,
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'last_page' => $pager->lastPage(),
            'stat' => $this->stat($request),
        ]);
    }

    /** 退款：仅成功且当日 */
    public function refund(Request $request, int $id)
    {
        try {
            $txn = DB::transaction(function () use ($id, $request) {
                $txn = CashCardTxn::query()->lockForUpdate()->findOrFail($id);

                if ($txn->status !== CashCardTxn::STATUS_SUCCESS) {
                    throw new \DomainException('该流水当前状态不可退款');
                }
                if (! $txn->refundable()) {
                    throw new \DomainException('仅当日成功的流水可退款');
                }

                $txn->status = CashCardTxn::STATUS_REFUND;
                $txn->amount = -abs((float) $txn->amount);
                $txn->fee = -abs((float) $txn->fee);
                $txn->refunded_by = optional($request->input('auth_user'))->id;
                $txn->refunded_at = now();
                $txn->save();

                return $txn;
            });
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->transform($txn), '退款成功');
    }

    public function export(Request $request)
    {
        $rows = $this->filtered($request)
            ->orderByDesc('txn_time')->orderByDesc('id')
            ->limit(self::EXPORT_LIMIT)
            ->get()
            ->map(fn (CashCardTxn $t) => $this->transform($t));

        $csv = "\u{FEFF}流水号,刷卡日期时间,客户,业务员,卡类型,卡号,金额,手续费,状态\n";
        foreach ($rows as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $r['serial_no'],
                $r['txn_time'],
                $this->csvCell($r['customer_name']),
                $this->csvCell($r['salesman_name']),
                $r['card_type_label'],
                $r['card_no_masked'],
                number_format((float) $r['amount'], 2, '.', ''),
                number_format((float) $r['fee'], 2, '.', ''),
                $r['status_label']
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="cash_card_txns.csv"',
        ]);
    }

    private function filtered(Request $request)
    {
        $q = CashCardTxn::query();

        if (($start = trim((string) $request->input('start_date', ''))) !== '') {
            $q->whereDate('txn_time', '>=', $start);
        }
        if (($end = trim((string) $request->input('end_date', ''))) !== '') {
            $q->whereDate('txn_time', '<=', $end);
        }
        if ($request->filled('customer_id')) {
            $q->where('customer_id', $request->input('customer_id'));
        }
        if ($request->filled('salesman_id')) {
            $q->where('salesman_id', $request->input('salesman_id'));
        }
        if (($last4 = trim((string) $request->input('card_last4', ''))) !== '') {
            $q->where('card_last4', 'like', '%'.$last4);
        }
        if (in_array($request->input('status'), [CashCardTxn::STATUS_SUCCESS, CashCardTxn::STATUS_FAIL, CashCardTxn::STATUS_REFUND], true)) {
            $q->where('status', $request->input('status'));
        }

        return $q;
    }

    /** 统计条：与列表同一筛选口径，不受分页影响 */
    private function stat(Request $request): array
    {
        $row = $this->filtered($request)
            ->selectRaw('COUNT(*) as cnt')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as success_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'fail' THEN 1 ELSE 0 END), 0) as fail_count")
            ->selectRaw('COALESCE(SUM(ABS(fee)), 0) as fee_sum')
            ->first();

        return [
            'count' => (int) $row->cnt,
            'success_amount' => round((float) $row->success_amount, 2),
            'fail_count' => (int) $row->fail_count,
            'fee_sum' => round((float) $row->fee_sum, 2),
        ];
    }

    private function transform(CashCardTxn $txn): array
    {
        return [
            'id' => $txn->id,
            'serial_no' => $txn->serial_no,
            'txn_time' => $txn->txn_time?->format('Y-m-d H:i:s'),
            'customer_id' => $txn->customer_id,
            'customer_name' => $txn->customer_name,
            'salesman_id' => $txn->salesman_id,
            'salesman_name' => $txn->salesman_name,
            'card_type' => $txn->card_type,
            'card_type_label' => CashCardTxn::cardTypeLabel($txn->card_type),
            'card_no_masked' => CashCardTxn::maskCardNo($txn->card_no),
            'card_last4' => $txn->card_last4,
            'amount' => (float) $txn->amount,
            'fee' => (float) $txn->fee,
            'status' => $txn->status,
            'status_label' => CashCardTxn::statusLabel($txn->status),
            'rel_no' => $txn->rel_no,
            'refundable' => $txn->refundable(),
            'refunded_at' => $txn->refunded_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function csvCell(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
