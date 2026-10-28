<?php

namespace Modules\Cash\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cash\Services\CashIntegralService;

/**
 * 积分查询控制器（D 域 D6，cash/integral/ledger 前缀，只读）。
 *
 * 数据源只有 member_consume_logs 里产生过积分变动的行，
 * 不 union cash_integral_grant_item（发放流水已落在那张表，重复计数）。
 */
class CashIntegralLedgerController extends Controller
{
    use ResponseTrait;

    private const EXPORT_LIMIT = 10000;

    public function __construct(private CashIntegralService $service) {}

    public function members(Request $request): JsonResponse
    {
        $pager = $this->service->ledgerMembers($request->all());

        return $this->success([
            'list' => collect($pager->items())->map(fn ($row) => $this->transformMember($row))->values(),
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'last_page' => $pager->lastPage(),
        ]);
    }

    public function flows(Request $request): JsonResponse
    {
        $memberId = (int) $request->input('member_id');
        if ($memberId <= 0) {
            return $this->error('请选择会员', 422);
        }

        $pager = $this->service->ledgerFlows($request->all());

        return $this->success([
            'list' => collect($pager->items())->map(fn ($row) => $this->transformFlow($row))->values(),
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'last_page' => $pager->lastPage(),
        ]);
    }

    public function export(Request $request)
    {
        $rows = $this->service->ledgerMemberRows($request->all(), self::EXPORT_LIMIT);

        $csv = "\u{FEFF}会员编号,会员名称,期初积分,本期发放,本期核销,期末积分,实时余额\n";
        foreach ($rows as $row) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s\n",
                $row->member_no,
                $this->csvCell($row->member_name),
                (int) $row->points_open,
                (int) $row->points_grant,
                (int) $row->points_verify,
                (int) $row->points_close,
                (int) $row->points_current
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="cash_integral_ledger.csv"',
        ]);
    }

    private function transformMember($row): array
    {
        return [
            'member_id' => (int) $row->id,
            'member_no' => $row->member_no,
            'member_name' => $row->member_name,
            'points_open' => (int) $row->points_open,
            'points_grant' => (int) $row->points_grant,
            'points_verify' => (int) $row->points_verify,
            'points_close' => (int) $row->points_close,
            'points_current' => (int) $row->points_current,
        ];
    }

    private function transformFlow($row): array
    {
        return [
            'id' => (int) $row->id,
            'log_no' => $row->log_no,
            'member_id' => (int) $row->member_id,
            'member_name' => $row->member_name,
            'date' => $row->created_at?->format('Y-m-d H:i:s'),
            'consume_type' => $row->consume_type,
            'consume_type_label' => $this->consumeTypeLabel((string) $row->consume_type),
            'direction' => $row->direction,
            'points' => (int) $row->points,
            'points_after' => (int) $row->points_after,
            'related_order_no' => $row->related_order_no,
            'remark' => $row->remark,
        ];
    }

    private function consumeTypeLabel(string $type): string
    {
        return [
            CashIntegralService::CONSUME_TYPE_GRANT => '积分发放',
            CashIntegralService::CONSUME_TYPE_USE => '积分核销',
            'order' => '消费订单',
            'order_refund' => '订单退款',
            'recharge' => '充值',
            'recharge_refund' => '充值退款',
            'points_deduct' => '积分抵扣',
        ][$type] ?? $type;
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
