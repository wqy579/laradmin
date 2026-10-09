<?php

namespace Modules\BorrowReturn\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\BorrowReturn\Models\BorrowOrder;
use Modules\BorrowReturn\Models\BorrowReturnOrder;

/**
 * 借货汇总（BorrowSummary）
 *
 * 统计卡片 + 客户借货明细 + 近 30 天借货/还货趋势。
 */
class BorrowSummaryController extends Controller
{
    use ResponseTrait;

    private function borrowQuery(Request $request)
    {
        $q = BorrowOrder::query()->where('status', '<>', BorrowOrder::STATUS_CANCELLED);
        if ($request->filled('customer_name')) {
            $q->where('customer_name', 'like', '%'.trim((string) $request->input('customer_name')).'%');
        }
        if ($request->filled('salesman_id')) {
            $q->where('salesman_id', (int) $request->input('salesman_id'));
        }
        if ($request->filled('start_date')) {
            $q->whereDate('borrow_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $q->whereDate('borrow_date', '<=', $request->input('end_date'));
        }

        return $q;
    }

    /** 统计卡片 */
    public function index(Request $request)
    {
        $borrows = $this->borrowQuery($request)->get();

        $borrowTotal = (float) $borrows->whereIn('status', [
            BorrowOrder::STATUS_UNRETURNED, BorrowOrder::STATUS_PARTIAL,
            BorrowOrder::STATUS_CLEARED, BorrowOrder::STATUS_CONVERTED,
        ])->sum('total_amount');

        $unreturnedAmount = (float) $borrows->whereIn('status', [
            BorrowOrder::STATUS_UNRETURNED, BorrowOrder::STATUS_PARTIAL,
        ])->sum(fn ($r) => (float) $r->total_amount - (float) $r->returned_amount);

        $returnedAmount = (float) $borrows->whereIn('status', [
            BorrowOrder::STATUS_UNRETURNED, BorrowOrder::STATUS_PARTIAL, BorrowOrder::STATUS_CLEARED,
        ])->sum('returned_amount');

        $customerCount = $borrows->whereIn('status', [
            BorrowOrder::STATUS_UNRETURNED, BorrowOrder::STATUS_PARTIAL,
            BorrowOrder::STATUS_CLEARED, BorrowOrder::STATUS_CONVERTED,
        ])->unique('customer_id')->count();

        return $this->success([
            'cards' => [
                ['key' => 'borrow_total', 'label' => '借货总额', 'value' => round($borrowTotal, 2)],
                ['key' => 'unreturned_amount', 'label' => '未还金额', 'value' => round($unreturnedAmount, 2)],
                ['key' => 'returned_amount', 'label' => '已还金额', 'value' => round($returnedAmount, 2)],
                ['key' => 'customer_count', 'label' => '借货客户数', 'value' => $customerCount],
            ],
        ]);
    }

    /** 客户借货明细 */
    public function customerDetail(Request $request)
    {
        $q = $this->borrowQuery($request);
        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        $rows = $q->select([
            'customer_id', 'customer_name', 'contact', 'contact_phone',
            DB::raw('COUNT(*) as borrow_times'),
            DB::raw('SUM(total_amount) as borrow_total'),
            DB::raw('SUM(returned_amount) as returned_total'),
            // 借还货查询要按「数量」汇总，不只是金额
            DB::raw('SUM(total_qty) as borrow_qty'),
            DB::raw('SUM(returned_qty) as returned_qty_sum'),
            DB::raw('MAX(borrow_date) as last_borrow_date'),
        ])->groupBy('customer_id', 'customer_name', 'contact', 'contact_phone')
            ->orderByDesc('borrow_total')
            ->paginate($pageSize, ['*'], 'page', $page);

        $list = $rows->getCollection()->map(function ($r) {
            $borrowTotal = (float) $r->borrow_total;
            $returnedTotal = (float) $r->returned_total;

            return [
                'customer_id' => $r->customer_id,
                'customer_name' => $r->customer_name,
                'contact' => $r->contact,
                'contact_phone' => $r->contact_phone,
                'borrow_times' => (int) $r->borrow_times,
                'borrow_total' => round($borrowTotal, 2),
                'returned_amount' => round($returnedTotal, 2),
                'unreturned_amount' => round($borrowTotal - $returnedTotal, 2),
                // 借货 / 已还 / 待还 数量
                'borrow_qty' => (int) $r->borrow_qty,
                'returned_qty' => (int) $r->returned_qty_sum,
                'unreturned_qty' => max(0, (int) $r->borrow_qty - (int) $r->returned_qty_sum),
                'last_borrow_date' => $r->last_borrow_date,
            ];
        });

        return $this->paginated($rows->setCollection($list));
    }

    /** 近 30 天借货/还货金额趋势 */
    public function trend(Request $request)
    {
        $days = min(60, max(7, $request->integer('days', 30)));
        $start = now()->subDays($days - 1)->startOfDay();

        $borrow = BorrowOrder::query()
            ->where('status', '<>', BorrowOrder::STATUS_CANCELLED)
            ->where('borrow_date', '>=', $start->toDateString())
            ->select(DB::raw('DATE(borrow_date) as d'), DB::raw('SUM(total_amount) as amount'))
            ->groupBy('d')->pluck('amount', 'd');

        $return = BorrowReturnOrder::query()
            ->where('status', BorrowReturnOrder::STATUS_APPROVED)
            ->where('return_date', '>=', $start->toDateString())
            ->select(DB::raw('DATE(return_date) as d'), DB::raw('SUM(total_amount) as amount'))
            ->groupBy('d')->pluck('amount', 'd');

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $series[] = [
                'date' => $date,
                'borrow' => round((float) ($borrow[$date] ?? 0), 2),
                'return' => round((float) ($return[$date] ?? 0), 2),
            ];
        }

        return $this->success(['list' => $series]);
    }
}
