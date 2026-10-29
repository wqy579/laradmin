<?php

namespace Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Asset\Models\AssetDepreciation;
use Modules\Asset\Services\AssetDepreciationService;

/**
 * 资产折旧控制器（D4，asset/depreciations 前缀）
 *
 * 左栏「本期应折明细」= 指定期间预览（仅在用资产）；
 * 右栏「执行流水」= 按月聚合的折旧批次。
 * 计提动作由 Service 收口（期间锁定 + 按部门拆分凭证）。
 */
class AssetDepreciationController extends Controller
{
    use ResponseTrait;

    public function __construct(private AssetDepreciationService $service) {}

    /** 本期应折明细（左栏）：按期间预览，字段名对齐前端 mock 约定 */
    public function preview(Request $request)
    {
        $month = trim((string) $request->input('month', now()->format('Y-m')));

        $items = collect($this->service->preview($month))->map(fn ($i) => [
            'id' => $i['id'],
            'code' => $i['asset_no'],
            'name' => $i['asset_name'],
            'original' => $i['original_value'],
            'month_amount' => $i['month_amount'],
            'depreciated' => $i['depreciated'],
            'net' => $i['net'],
            'after_depreciated' => $i['after_depreciated'],
            'after_net' => $i['after_net'],
            'department_id' => $i['department_id'],
            'department_name' => $i['department_name'],
            'period' => $month,
        ])->values();

        $totalAmount = round((float) $items->sum('month_amount'), 2);
        $afterNet = round((float) $items->sum('after_net'), 2);

        return $this->success([
            'month' => $month,
            'list' => $items,
            'count' => $items->count(),
            'total_amount' => $totalAmount,
            'after_net' => $afterNet,
        ]);
    }

    /** 折旧执行流水（右栏）：按月聚合批次 */
    public function runs(Request $request)
    {
        $pager = $this->service->runs($this->page($request), $this->pageSize($request));

        $list = collect($pager['list'])->map(fn ($r) => [
            'id' => $r->id,
            'batch_no' => $r->batch_no,
            'month' => $r->month,
            'count' => (int) $r->count,
            'amount' => round((float) $r->amount, 2),
        ])->values();

        return $this->success([
            'list' => $list,
            'total' => $pager['total'],
            'page' => $pager['page'],
            'page_size' => $pager['page_size'],
            'last_page' => $pager['last_page'],
        ]);
    }

    /** 某月折旧记录（明细，供后续查看某批次的逐条记录） */
    public function records(Request $request)
    {
        $month = trim((string) $request->input('month', now()->format('Y-m')));
        $pager = $this->service->records($month, $this->page($request), $this->pageSize($request));

        $list = collect($pager['list'])->map(fn (AssetDepreciation $r) => [
            'id' => $r->id,
            'batch_no' => $r->batch_no,
            'month' => $r->month,
            'asset_no' => $r->asset_no,
            'asset_name' => $r->asset_name,
            'original_value' => (float) $r->original_value,
            'residual_rate' => (float) $r->residual_rate,
            'month_amount' => (float) $r->month_amount,
            'accumulated_dep' => (float) $r->accumulated_dep,
            'net_value' => (float) $r->net_value,
            'department_name' => $r->department_name,
            'voucher_id' => $r->voucher_id,
        ])->values();

        return $this->success([
            'month' => $month,
            'list' => $list,
            'total' => $pager['total'],
            'page' => $pager['page'],
            'page_size' => $pager['page_size'],
            'last_page' => $pager['last_page'],
        ]);
    }

    /** 执行计提：批量生成折旧记录 + 按部门拆分凭证 + 回写卡片 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $user = $request->input('auth_user');
        $makerId = (int) ($user?->id ?? 0);
        $makerName = (string) ($user?->real_name ?: $user?->username ?: '系统');

        $result = $this->service->run($data['month'], $makerId, $makerName);

        $message = "期间 {$result['month']} 折旧计提完成：共 {$result['count']} 项，本月折旧合计 ".number_format($result['amount'], 2);
        if (! empty($result['voucher_ids'])) {
            $message .= '，已按部门生成 '.count($result['voucher_ids']).' 张折旧凭证';
        } elseif ($result['voucher_error']) {
            $message .= '；凭证未生成：'.$result['voucher_error'];
        }

        return $this->created([
            'batch_no' => $result['batch_no'],
            'id' => $result['id'],
            'month' => $result['month'],
            'count' => $result['count'],
            'amount' => $result['amount'],
            'voucher_ids' => $result['voucher_ids'],
            'voucher_error' => $result['voucher_error'],
        ], $message);
    }

    private function page(Request $request): int
    {
        return max(1, (int) $request->input('page', 1));
    }

    private function pageSize(Request $request): int
    {
        return min(200, max(1, (int) $request->input('page_size', 20)));
    }
}
