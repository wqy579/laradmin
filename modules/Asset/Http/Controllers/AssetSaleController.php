<?php

namespace Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Asset\Models\AssetCard;
use Modules\Asset\Models\AssetSale;
use Modules\Asset\Services\AssetSaleService;
use Modules\Auth\Models\User;

/**
 * 资产变卖控制器（D4，asset/sales 前缀）
 *
 * 左栏表单保存 → 右栏变卖流水；损益 = 变卖价 − 净值由 Service 收口。
 */
class AssetSaleController extends Controller
{
    use ResponseTrait;

    public function __construct(private AssetSaleService $service) {}

    /** 变卖流水（分页） */
    public function index(Request $request)
    {
        $pager = $this->service->sales([
            'start_date' => trim((string) $request->input('start_date', '')),
            'end_date' => trim((string) $request->input('end_date', '')),
            'keyword' => trim((string) $request->input('keyword', '')),
        ], $this->page($request), $this->pageSize($request));

        $list = collect($pager['list'])->map(fn (AssetSale $s) => [
            'id' => $s->id,
            'sale_no' => $s->sale_no,
            'asset_card_id' => $s->asset_card_id,
            'card_code' => $s->asset_no,
            'card_name' => $s->asset_name,
            'card_net' => (float) $s->net_value,
            'sale_date' => $s->sale_date->toDateString(),
            'sale_amount' => (float) $s->sale_amount,
            'net_value' => (float) $s->net_value,
            'gain_loss' => (float) $s->gain_loss,
            'closing_fee' => (float) $s->closing_fee,
            'buyer' => $s->buyer,
            'handler' => $s->handler,
            'voucher_id' => $s->voucher_id,
            'pnl' => (float) $s->gain_loss,
        ])->values();

        return $this->success([
            'list' => $list,
            'total' => $pager['total'],
            'page' => $pager['page'],
            'page_size' => $pager['page_size'],
            'last_page' => $pager['last_page'],
        ]);
    }

    /** 保存变卖单：算损益 + 卡片状态置「已售」+（可选）处置凭证 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'asset_card_id' => ['required', 'integer'],
            'sale_date' => ['required', 'date'],
            'sale_amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'closing_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'buyer' => ['required', 'string', 'max:100'],
            'handler' => ['nullable', 'string', 'max:60'],
            'remark' => ['nullable', 'string', 'max:255'],
            'register_voucher' => ['nullable', 'boolean'],
        ]);

        $user = $request->input('auth_user');
        $makerId = (int) ($user?->id ?? 0);
        $makerName = (string) ($user?->real_name ?: $user?->username ?: '系统');

        $result = $this->service->store($data, $makerId, $makerName);
        $sale = $result['sale'];

        $message = '变卖单 '.$sale->sale_no.' 已保存，'.$sale->asset_name.' 状态已改为「已售」，'
            .'变卖损益：'.($result['pnl'] >= 0 ? '盈利' : '亏损').' '.number_format(abs($result['pnl']), 2);
        if ($result['voucher_id']) {
            $message .= '，并已登记处置凭证';
        } elseif ($result['voucher_error']) {
            $message .= '；凭证未生成：'.$result['voucher_error'];
        }

        return $this->created([
            'id' => $sale->id,
            'sale_no' => $sale->sale_no,
            'sale_amount' => (float) $sale->sale_amount,
            'pnl' => $result['pnl'],
            'voucher_id' => $result['voucher_id'],
            'voucher_error' => $result['voucher_error'],
        ], $message);
    }

    /** 表单下拉：在用的可选资产 + 经手人（复用主数据） */
    public function options()
    {
        // 仅「在用」状态资产可变卖（sold/disposed/idle 不参与）
        $cards = AssetCard::query()
            ->where('status', AssetCard::STATUS_INUSE)
            ->orderBy('asset_no')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'code' => $c->asset_no,
                'name' => $c->asset_name,
                'original' => (float) $c->original_value,
                'depreciated' => (float) $c->accumulated_dep,
                'net' => (float) $c->net_value,
                'status' => $c->status,
            ]);

        $users = User::query()
            ->where('status', 1)
            ->orderBy('id')
            ->get(['id', 'real_name', 'username'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->real_name ?: $u->username]);

        return $this->success([
            'cards' => $cards,
            'users' => $users,
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
