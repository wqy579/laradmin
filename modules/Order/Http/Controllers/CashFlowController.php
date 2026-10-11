<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashFlowController extends Controller
{
    use ResponseTrait;

    /** 现金流水：收款(正) + 付款(负) + 费用(负) + 红冲 */
    public function index(Request $request)
    {
        $quickDate = $request->input('quick_date');
        $dateFn = function ($q) use ($quickDate) {
            if ($quickDate === 'today') {
                $q->whereDate('flow_date', now()->toDateString());
            } elseif ($quickDate === 'week') {
                $q->whereDate('flow_date', '>=', now()->subDays(6)->toDateString());
            } elseif ($quickDate === 'month') {
                $q->whereMonth('flow_date', now()->month)->whereYear('flow_date', now()->year);
            }
        };

        // 收款（全部状态）
        // related_type 细分来源，便于财务区分不同类型收款凭证：
        // StockAdjust=库存溢余（调整单产生的溢余入库回款）
        // MemberRecharge=会员储值（会员充值到账，不是销售收款，避免与应收核销混淆）
        $receives = DB::table('cash_flows')
            ->where('flow_type', 'receive')
            ->select('flow_date as date', DB::raw("CASE related_type WHEN 'StockAdjust' THEN '库存溢余' WHEN 'MemberRecharge' THEN '会员储值' ELSE '收款' END as type"), 'remark as description', 'amount');
        $dateFn($receives);

        // 付款（负，全部状态）
        $pays = DB::table('cash_flows')
            ->where('flow_type', 'pay')
            ->select('flow_date as date', DB::raw("'付款' as type"), 'remark as description', DB::raw('-amount as amount'));
        $dateFn($pays);

        // 费用（负，全部状态）
        $expenses = DB::table('cash_flows')
            ->where('flow_type', 'expense')
            ->select('flow_date as date', DB::raw("CASE WHEN related_type = 'StockAdjust' THEN '库存损耗' ELSE '费用' END as type"), 'remark as description', DB::raw('-amount as amount'));
        $dateFn($expenses);

        // 红冲（负，全部状态）
        $redFlushes = DB::table('cash_flows')
            ->where('flow_type', 'red_flush')
            ->select('flow_date as date', DB::raw("'红冲' as type"), 'remark as description', DB::raw('-amount as amount'));
        $dateFn($redFlushes);

        $list = $receives->unionAll($pays)->unionAll($expenses)->unionAll($redFlushes)
            ->orderByDesc('date')->get();

        return $this->success(['list' => $list, 'total' => $list->count()]);
    }
}
