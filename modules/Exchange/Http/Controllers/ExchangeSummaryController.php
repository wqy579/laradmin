<?php

namespace Modules\Exchange\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Exchange\Models\ExchangeOrder;
use Modules\Exchange\Models\ExchangeOrderItem;

/**
 * 换货汇总（ExchangeSummary）
 *
 * 统计卡片 + 原因分布 + 商品换货排行 + 客户明细。
 */
class ExchangeSummaryController extends Controller
{
    use ResponseTrait;

    private function scope(Request $request)
    {
        $q = ExchangeOrder::query()->where('status', ExchangeOrder::STATUS_APPROVED);
        if ($request->filled('start_date')) {
            $q->whereDate('exchange_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $q->whereDate('exchange_date', '<=', $request->input('end_date'));
        }

        return $q;
    }

    public function index(Request $request)
    {
        $orders = $this->scope($request)->get();

        return $this->success([
            'cards' => [
                ['key' => 'order_count', 'label' => '换货总单数', 'value' => $orders->count()],
                ['key' => 'amount_out', 'label' => '换出总金额', 'value' => round((float) $orders->sum('amount_out'), 2)],
                ['key' => 'amount_in', 'label' => '换入总金额', 'value' => round((float) $orders->sum('amount_in'), 2)],
                ['key' => 'diff_total', 'label' => '差价总额', 'value' => round((float) $orders->sum('diff_amount'), 2)],
            ],
        ]);
    }

    /** 换货原因分布 */
    public function reasonDistribution(Request $request)
    {
        $rows = $this->scope($request)
            ->select('exchange_reason', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(diff_amount) as diff'))
            ->groupBy('exchange_reason')
            ->orderByDesc('cnt')
            ->get();

        return $this->success(['list' => $rows->map(fn ($r) => [
            'reason' => $r->exchange_reason,
            'count' => (int) $r->cnt,
            'diff' => round((float) $r->diff, 2),
        ])]);
    }

    /** 商品换货排行（前 10 换出 / 换入） */
    public function productRank(Request $request)
    {
        $approvedIds = $this->scope($request)->pluck('id');

        $out = ExchangeOrderItem::query()
            ->whereIn('order_id', $approvedIds)
            ->select('product_id_out as product_id', 'product_name_out as product_name',
                DB::raw('SUM(qty) as qty'), DB::raw('SUM(amount_out) as amount'))
            ->groupBy('product_id_out', 'product_name_out')
            ->orderByDesc('qty')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'product_name' => $r->product_name, 'qty' => (int) $r->qty, 'amount' => round((float) $r->amount, 2)]);

        $in = ExchangeOrderItem::query()
            ->whereIn('order_id', $approvedIds)
            ->select('product_id_in as product_id', 'product_name_in as product_name',
                DB::raw('SUM(qty) as qty'), DB::raw('SUM(amount_in) as amount'))
            ->groupBy('product_id_in', 'product_name_in')
            ->orderByDesc('qty')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'product_name' => $r->product_name, 'qty' => (int) $r->qty, 'amount' => round((float) $r->amount, 2)]);

        return $this->success(['out' => $out, 'in' => $in]);
    }

    /** 客户换货明细 */
    public function customerDetail(Request $request)
    {
        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        $rows = $this->scope($request)
            ->select([
                'customer_id', 'customer_name',
                DB::raw('COUNT(*) as exchange_times'),
                DB::raw('SUM(amount_out) as amount_out'),
                DB::raw('SUM(amount_in) as amount_in'),
                DB::raw('SUM(diff_amount) as diff'),
                DB::raw('MAX(exchange_date) as last_exchange_date'),
            ])
            ->groupBy('customer_id', 'customer_name')
            ->orderByDesc('amount_out')
            ->paginate($pageSize, ['*'], 'page', $page);

        $list = $rows->getCollection()->map(function ($r) {
            return [
                'customer_id' => $r->customer_id,
                'customer_name' => $r->customer_name,
                'exchange_times' => (int) $r->exchange_times,
                'amount_out' => round((float) $r->amount_out, 2),
                'amount_in' => round((float) $r->amount_in, 2),
                'diff' => round((float) $r->diff, 2),
                'last_exchange_date' => $r->last_exchange_date,
            ];
        });

        return $this->paginated($rows->setCollection($list));
    }
}
