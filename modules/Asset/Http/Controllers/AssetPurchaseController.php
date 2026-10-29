<?php

namespace Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Asset\Models\AssetPurchase;
use Modules\Asset\Services\AssetPurchaseService;
use Modules\Auth\Models\Department;
use Modules\Auth\Models\User;

/**
 * 资产购置单控制器（D3，asset/purchases 前缀）
 *
 * 左栏表单保存 → 右栏购置流水，原值与卡片生成都由 Service 收口。
 */
class AssetPurchaseController extends Controller
{
    use ResponseTrait;

    public function __construct(private AssetPurchaseService $service) {}

    /** 购置流水分页：日期区间 / 单号 / 资产名称 */
    public function index(Request $request)
    {
        $pager = $this->service->purchases([
            'start_date' => trim((string) $request->input('start_date', '')),
            'end_date' => trim((string) $request->input('end_date', '')),
            'purchase_no' => trim((string) $request->input('purchase_no', '')),
            'asset_name' => trim((string) $request->input('asset_name', '')),
        ], $this->page($request), $this->pageSize($request));

        $list = collect($pager->items())->map(fn (AssetPurchase $p) => [
            'id' => $p->id,
            'purchase_no' => $p->purchase_no,
            'purchase_date' => $p->purchase_date->toDateString(),
            'asset_name' => $p->asset_name,
            'spec' => $p->spec,
            'quantity' => $p->quantity,
            'unit_price' => (float) $p->unit_price,
            'original_value' => (float) $p->original_value,
            'use_months' => $p->use_months,
            'residual_rate' => (float) $p->residual_rate,
            'dep_method' => $p->dep_method,
            'dep_method_label' => AssetPurchase::depMethodLabel($p->dep_method),
            'department_name' => $p->department_name,
            'user_name' => $p->user_name,
            'supplier' => $p->supplier,
            'voucher_id' => $p->voucher_id,
            'remark' => $p->remark,
        ]);

        return $this->success([
            'list' => $list,
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'last_page' => $pager->lastPage(),
        ]);
    }

    /** 保存购置单：重算原值 + 生成资产卡片 +（可选）现金支出凭证 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'purchase_date' => ['required', 'date'],
            'asset_name' => ['required', 'string', 'max:100'],
            'spec' => ['nullable', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'original_value' => ['nullable', 'numeric'],
            'use_months' => ['required', 'integer', 'min:1', 'max:1200'],
            'residual_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'dep_method' => ['nullable', 'in:straight,average'],
            'department_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'supplier' => ['nullable', 'string', 'max:100'],
            'remark' => ['nullable', 'string', 'max:255'],
            'register_voucher' => ['nullable', 'boolean'],
        ]);

        $user = $request->input('auth_user');
        $makerId = (int) ($user?->id ?? 0);
        $makerName = (string) ($user?->real_name ?: $user?->username ?: '系统');

        $result = $this->service->store($data, $makerId, $makerName);
        $purchase = $result['purchase'];

        $message = '购置单 '.$purchase->purchase_no.' 已保存，生成 '.$result['cards'].' 张资产卡片';
        if ($result['voucher_id']) {
            $message .= '，并已登记现金支出凭证';
        } elseif ($result['voucher_error']) {
            $message .= '；凭证未生成：'.$result['voucher_error'];
        }

        return $this->created([
            'id' => $purchase->id,
            'purchase_no' => $purchase->purchase_no,
            'original_value' => (float) $purchase->original_value,
            'cards' => $result['cards'],
            'voucher_id' => $result['voucher_id'],
            'voucher_error' => $result['voucher_error'],
        ], $message);
    }

    /** 表单下拉：部门 / 使用人 / 历史供应商（去重） */
    public function options()
    {
        $departments = Department::query()
            ->where('status', 1)
            ->orderBy('sort')->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'name' => (string) $d->name]);

        $users = User::query()
            ->where('status', 1)
            ->orderBy('id')
            ->get(['id', 'real_name', 'username'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->real_name ?: $u->username]);

        $suppliers = AssetPurchase::query()
            ->whereNotNull('supplier')->where('supplier', '!=', '')
            ->distinct()->orderBy('supplier')
            ->pluck('supplier')
            ->map(fn ($s) => ['name' => (string) $s]);

        return $this->success([
            'departments' => $departments,
            'users' => $users,
            'suppliers' => $suppliers,
        ]);
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
