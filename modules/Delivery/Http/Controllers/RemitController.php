<?php

namespace Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Delivery\Models\DeliveryCollection;
use Modules\Delivery\Models\DeliveryRemit;
use Modules\Delivery\Models\DeliveryRemitItem;

/**
 * 上交货款管理（配送员→出纳）。配送员按方式分类上交已收货款，
 * 出纳确认后资金从「配送员在途」转入公司账户。
 */
class RemitController extends Controller
{
    use ResponseTrait;

    public function index(Request $request)
    {
        $query = DeliveryRemit::query()->with('items');

        if ($no = $request->input('remit_no')) {
            $query->where('remit_no', 'like', "%{$no}%");
        }
        if ($person = $request->input('delivery_person_name')) {
            $query->where('delivery_person_name', 'like', "%{$person}%");
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($range = $request->input('date_range')) {
            [$start, $end] = is_array($range) ? $range : explode(',', (string) $range);
            if ($start ?? null) {
                $query->where('remit_date', '>=', $start);
            }
            if ($end ?? null) {
                $query->where('remit_date', '<=', $end);
            }
        }

        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(200, max(10, (int) $request->input('page_size', 20)));

        return $this->paginated($query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page));
    }

    public function show(int $id)
    {
        $remit = DeliveryRemit::with('items')->find($id);
        if (! $remit) {
            return $this->error('上交单不存在', 404);
        }

        return $this->success($remit);
    }

    /** 待上交汇总：配送员各方式已收-已上交差额 */
    public function unremitSummary(Request $request)
    {
        $validated = $request->validate(['delivery_person_id' => 'nullable|integer']);
        $admin = auth('admin')->user();
        $personId = $validated['delivery_person_id'] ?? $admin?->id;
        if (! $personId) {
            return $this->error('请选择配送员', 422);
        }

        // 各方式已收金额（挂账不上交）
        $received = DeliveryCollection::where('delivery_person_id', $personId)
            ->where('payment_method', '!=', DeliveryCollection::METHOD_CREDIT)
            ->where('status', '!=', 'pending')
            ->select('payment_method', DB::raw('SUM(received_amount) as total'))
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        // 各方式已上交金额
        $remitted = DB::table('delivery_remit_items as ri')
            ->join('delivery_remit as r', 'r.id', '=', 'ri.remit_id')
            ->where('r.delivery_person_id', $personId)
            ->whereIn('r.status', ['remitted', 'confirmed'])
            ->select('ri.payment_method', DB::raw('SUM(ri.amount) as total'))
            ->groupBy('ri.payment_method')
            ->pluck('total', 'ri.payment_method');

        $methods = [DeliveryCollection::METHOD_CASH, DeliveryCollection::METHOD_WECHAT, DeliveryCollection::METHOD_ALIPAY, DeliveryCollection::METHOD_BANK];
        $summary = [];
        foreach ($methods as $m) {
            $r = (float) ($received[$m] ?? 0);
            $d = (float) ($remitted[$m] ?? 0);
            $summary[$m] = ['received' => $r, 'remitted' => $d, 'pending' => round($r - $d, 2)];
        }

        return $this->success($summary);
    }

    /** 新增上交：按方式分类上交，金额≤待上交 */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'delivery_person_id' => 'nullable|integer',
            'remit_date' => 'nullable|date',
            'cash_amount' => 'nullable|numeric|min:0',
            'wechat_amount' => 'nullable|numeric|min:0',
            'alipay_amount' => 'nullable|numeric|min:0',
            'bank_amount' => 'nullable|numeric|min:0',
            'remark' => 'nullable|string',
        ]);

        $admin = auth('admin')->user();
        $personId = $validated['delivery_person_id'] ?? $admin?->id;
        $person = DB::table('auth_user')->where('id', $personId)->first();
        if (! $person) {
            return $this->error('配送员不存在', 422);
        }
        $emp = DB::table('employees')->where('user_id', $person->id)->first();

        $amounts = [
            '现金' => (float) ($validated['cash_amount'] ?? 0),
            '微信' => (float) ($validated['wechat_amount'] ?? 0),
            '支付宝' => (float) ($validated['alipay_amount'] ?? 0),
            '银行卡' => (float) ($validated['bank_amount'] ?? 0),
        ];
        $total = round(array_sum($amounts), 2);
        if ($total <= 0) {
            return $this->error('上交金额必须大于0', 422);
        }

        // 校验各方式不超过待上交
        $summary = $this->unremitSummary(new Request(['delivery_person_id' => $personId]));
        $data = $summary->getData()->data ?? null;
        $pending = [];
        foreach ($amounts as $method => $amt) {
            if ($amt > 0) {
                $p = $data[$method]->pending ?? 0;
                if ($amt > $p + 0.01) {
                    return $this->error("{$method}上交金额不能超过待上交金额", 422);
                }
                $pending[$method] = $amt;
            }
        }

        $remitNo = $this->generateNo('SJ', 'delivery_remit', 'remit_no');
        $remitDate = $validated['remit_date'] ?? now()->toDateString();

        DB::beginTransaction();
        try {
            $remit = DeliveryRemit::create([
                'remit_no' => $remitNo,
                'remit_date' => $remitDate,
                'delivery_person_id' => $person->id,
                'delivery_person_name' => $person->username,
                'employee_id' => $emp?->id,
                'cash_amount' => $amounts['现金'],
                'wechat_amount' => $amounts['微信'],
                'alipay_amount' => $amounts['支付宝'],
                'bank_amount' => $amounts['银行卡'],
                'total_amount' => $total,
                'status' => DeliveryRemit::STATUS_PENDING,
                'remark' => $validated['remark'] ?? null,
            ]);

            // 关联本次上交覆盖的收款单（按方式FIFO）
            foreach ($pending as $method => $amt) {
                $cols = DeliveryCollection::where('delivery_person_id', $person->id)
                    ->where('payment_method', $method)
                    ->where('status', '!=', 'pending')
                    ->whereNotIn('id', function ($q) {
                        $q->select('collection_id')->from('delivery_remit_items')->whereNotNull('collection_id');
                    })
                    ->orderBy('id')
                    ->get(['id', 'collection_no', 'received_amount']);
                $left = $amt;
                foreach ($cols as $col) {
                    if ($left <= 0) {
                        break;
                    }
                    $use = min($left, (float) $col->received_amount);
                    DeliveryRemitItem::create([
                        'remit_id' => $remit->id,
                        'collection_id' => $col->id,
                        'collection_no' => $col->collection_no,
                        'payment_method' => $method,
                        'amount' => $use,
                    ]);
                    $left -= $use;
                }
            }

            // 状态直接转为已上交（配送员上交即提交）
            $remit->status = DeliveryRemit::STATUS_REMITTED;
            $remit->save();

            DB::commit();

            return $this->created($remit->fresh(['items']), '上交成功，待出纳确认');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        }
    }

    /** 出纳确认上交（资金从在途转公司） */
    public function confirm(Request $request, int $id)
    {
        $remit = DeliveryRemit::with('items')->find($id);
        if (! $remit) {
            return $this->error('上交单不存在', 404);
        }
        if ($remit->status !== DeliveryRemit::STATUS_REMITTED) {
            return $this->error('仅已上交状态可确认', 422);
        }
        $admin = auth('admin')->user();
        DB::beginTransaction();
        try {
            $remit->update([
                'status' => DeliveryRemit::STATUS_CONFIRMED,
                'confirmed_by' => $admin?->id,
                'confirmed_at' => now(),
            ]);

            // 写现金流水备查（flow_type=receive, related=DeliveryRemit）
            foreach ($remit->items as $item) {
                DB::table('cash_flows')->insert([
                    'flow_no' => 'CF'.date('YmdHis').strtoupper(\Illuminate\Support\Str::random(4)).$item->id,
                    'flow_type' => 'receive',
                    'related_id' => $remit->id,
                    'related_type' => 'DeliveryRemit',
                    'flow_date' => $remit->remit_date,
                    'amount' => (float) $item->amount,
                    'payment_method' => $item->payment_method,
                    'remark' => '配送员上交货款 '.$remit->remit_no,
                    'created_by' => $admin?->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            return $this->success($remit, '出纳确认完成');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        }
    }
}
