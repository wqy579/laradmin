<?php

namespace Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Asset\Models\AssetCard;
use Modules\Asset\Models\AssetPurchase;
use Modules\Asset\Services\AssetPurchaseService;
use Modules\Auth\Models\Department;

/**
 * 固定资产台账控制器（D3，asset/cards 前缀）
 *
 * 卡片视图与列表视图都打同一个 index 接口，前端只切渲染层，
 * 因此两视图的条数与金额天然一致（后端不提供第二条查询路径）。
 */
class AssetCardController extends Controller
{
    use ResponseTrait;

    public function __construct(private AssetPurchaseService $service) {}

    /** 台账分页（双视图共用）+ 原值/累计折旧/净值汇总 */
    public function index(Request $request)
    {
        $data = $this->service->ledger([
            'start_date' => trim((string) $request->input('start_date', '')),
            'end_date' => trim((string) $request->input('end_date', '')),
            'keyword' => trim((string) $request->input('keyword', '')),
            'status' => trim((string) $request->input('status', '')),
            'department_id' => $request->input('department_id') ?: null,
        ], max(1, (int) $request->input('page', 1)), min(200, max(1, (int) $request->input('page_size', 20))));

        $data['list'] = collect($data['list'])->map(fn (AssetCard $c) => [
            'id' => $c->id,
            'asset_no' => $c->asset_no,
            'asset_name' => $c->asset_name,
            'spec' => $c->spec,
            'original_value' => (float) $c->original_value,
            'accumulated_dep' => (float) $c->accumulated_dep,
            'net_value' => (float) $c->net_value,
            'use_months' => $c->use_months,
            'residual_rate' => (float) $c->residual_rate,
            'dep_method_label' => AssetPurchase::depMethodLabel($c->dep_method),
            'department_name' => $c->department_name,
            'user_name' => $c->user_name,
            'status' => $c->status,
            'status_label' => AssetCard::statusLabel($c->status),
            'acquire_date' => $c->acquire_date->toDateString(),
        ])->values();

        return $this->success($data);
    }

    /** 筛选下拉：部门与状态取台账实际出现过的值，不与新模块外的主数据接口耦合 */
    public function options()
    {
        $departments = Department::query()
            ->where('status', 1)
            ->orderBy('sort')->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'name' => (string) $d->name]);

        $statuses = collect([
            AssetCard::STATUS_INUSE, AssetCard::STATUS_IDLE, AssetCard::STATUS_DISPOSED,
        ])->map(fn ($s) => ['value' => $s, 'label' => AssetCard::statusLabel($s)]);

        $suppliers = DB::table('asset_purchase')
            ->whereNotNull('supplier')->where('supplier', '!=', '')
            ->distinct()->orderBy('supplier')
            ->pluck('supplier')
            ->map(fn ($s) => ['name' => (string) $s]);

        return $this->success([
            'departments' => $departments,
            'statuses' => $statuses,
            'suppliers' => $suppliers,
        ]);
    }
}
