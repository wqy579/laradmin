<?php

namespace Modules\Cash\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Cash\Http\Requests\CashHandoverRequest;
use Modules\Cash\Models\CashHandover;
use Modules\Cash\Services\CashHandoverService;
use Modules\Order\Models\Customer;
use Modules\Order\Models\SalesOrder;

/**
 * 收入上交款控制器（D2，cash/handover 前缀）
 *
 * 明细合计 = 头金额的校验在前端拦截之外，服务端同样强校验（Service 收口）；
 * 确认动作幂等，重复请求返回当前单据而非报错或重复记流水。
 */
class CashHandoverController extends Controller
{
    use ResponseTrait;

    private const EXPORT_LIMIT = 50000;

    public function __construct(private CashHandoverService $service) {}

    /** 下拉选项：收款账户、业务员、客户 */
    public function options()
    {
        $customers = Customer::query()
            ->orderBy('id')
            ->limit(2000)
            ->get(['id', 'name'])
            ->map(fn (Customer $c) => ['id' => $c->id, 'name' => $c->name])
            ->all();

        return $this->success([
            'accounts' => $this->service->accountOptions(),
            'salesmen' => $this->service->salesmanOptions(),
            'customers' => $customers,
        ]);
    }

    /** 上交款单列表（录入页左栏 / 查询页共用） */
    public function index(Request $request)
    {
        $q = $this->filtered($request);
        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(200, max(1, (int) $request->input('page_size', 20)));

        $pager = $q->orderByDesc('handover_date')->orderByDesc('id')
            ->withCount('items')
            ->with(['account:id,name', 'confirmer:id,real_name,username'])
            ->paginate($pageSize, ['*'], 'page', $page);

        return $this->success([
            'list' => collect($pager->items())->map(fn (CashHandover $h) => $this->transform($h))->values(),
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'last_page' => $pager->lastPage(),
            'stat' => $this->stat($request),
        ]);
    }

    public function show(int $id)
    {
        $handover = CashHandover::query()->withCount('items')
            ->with(['account:id,name', 'confirmer:id,real_name,username', 'items'])
            ->findOrFail($id);

        return $this->success($this->transform($handover, true));
    }

    /** 新建保存：头 + 明细，服务端校验明细合计 = 头金额 */
    public function store(CashHandoverRequest $request)
    {
        $items = $this->resolveItems($request->input('items'));
        if ($items === null) {
            return $this->error('关联订单不存在或已被删除', 422);
        }

        try {
            $handover = $this->service->create(
                $request->only(['handover_date', 'salesman_id', 'salesman_name', 'account_id', 'amount', 'remark']),
                $items,
                optional($request->input('auth_user'))->id
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->transform($handover->load('account:id,name'), true), '已保存，可在「上交款查询」中确认');
    }

    /** 确认（幂等） */
    public function confirm(Request $request, int $id)
    {
        $handover = $this->service->confirm($id, optional($request->input('auth_user'))->id);

        return $this->success($this->transform($handover), '已确认');
    }

    /** 批量确认：逐单走幂等确认 */
    public function batchConfirm(CashHandoverRequest $request)
    {
        $operatorId = optional($request->input('auth_user'))->id;
        $confirmed = 0;
        foreach ($request->input('ids') as $id) {
            $this->service->confirm((int) $id, $operatorId);
            $confirmed++;
        }

        return $this->success(['confirmed' => $confirmed], "已确认 {$confirmed} 单");
    }

    /** 可关联订单：未收清且未作废/未红冲 */
    public function orders(Request $request)
    {
        $q = SalesOrder::query()
            ->whereNotIn('status', ['cancelled', '已红冲', '已取消'])
            ->whereRaw('total_amount - paid_amount > 0.01');

        if (($keyword = trim((string) $request->input('keyword', ''))) !== '') {
            $q->where(function ($sub) use ($keyword) {
                $sub->where('order_no', 'like', '%'.$keyword.'%')
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', '%'.$keyword.'%'));
            });
        }
        if ($request->filled('customer_id')) {
            $q->where('customer_id', $request->input('customer_id'));
        }
        if ($request->filled('salesman_id')) {
            $q->where('salesman_id', $request->input('salesman_id'));
        }
        if (($start = trim((string) $request->input('start_date', ''))) !== '') {
            $q->whereDate('order_date', '>=', $start);
        }
        if (($end = trim((string) $request->input('end_date', ''))) !== '') {
            $q->whereDate('order_date', '<=', $end);
        }
        // 本单已关联的订单不再出现在选择列表里
        if (is_array($exclude = $request->input('exclude_ids'))) {
            $q->whereNotIn('id', collect($exclude)->filter()->all());
        }

        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(200, max(1, (int) $request->input('page_size', 20)));

        $pager = $q->orderByDesc('order_date')->orderByDesc('id')
            ->with('customer:id,name')
            ->paginate($pageSize, ['*'], 'page', $page);

        $rows = collect($pager->items())->map(fn (SalesOrder $o) => [
            'id' => $o->id,
            'order_no' => $o->order_no,
            'customer_id' => $o->customer_id,
            'customer_name' => $o->customer?->name,
            'salesman_id' => $o->salesman_id,
            'salesman_name' => $o->salesman_name,
            'order_date' => $o->order_date?->format('Y-m-d'),
            'amount' => (float) $o->total_amount,
            'paid_amount' => (float) $o->paid_amount,
            'unpaid_amount' => round((float) $o->total_amount - (float) $o->paid_amount, 2),
        ])->values();

        return $this->success([
            'list' => $rows,
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'last_page' => $pager->lastPage(),
        ]);
    }

    public function export(Request $request)
    {
        $rows = $this->filtered($request)
            ->withCount('items')
            ->with(['account:id,name', 'confirmer:id,real_name,username'])
            ->orderByDesc('handover_date')->orderByDesc('id')
            ->limit(self::EXPORT_LIMIT)
            ->get()
            ->map(fn (CashHandover $h) => $this->transform($h));

        $csv = "\u{FEFF}上交单号,上交日期,上交人,收款账户,订单数,金额,状态,确认人,确认时间,备注\n";
        foreach ($rows as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $r['no'],
                $r['date'],
                $this->csvCell($r['salesman_name']),
                $this->csvCell($r['account_name']),
                $r['order_count'],
                number_format((float) $r['amount'], 2, '.', ''),
                $r['status_label'],
                $this->csvCell($r['confirmed_name']),
                $r['confirmed_at'] ?? '',
                $this->csvCell($r['remark'])
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="cash_handovers.csv"',
        ]);
    }

    /**
     * 明细整形：按订单补全冗余字段，任一订单不存在则整单拒绝
     *
     * @return array|null null 表示订单校验失败
     */
    private function resolveItems($rawItems): ?array
    {
        $raw = collect($rawItems ?: []);
        $orders = SalesOrder::query()
            ->with('customer:id,name')
            ->findMany($raw->pluck('sales_order_id')->filter()->unique())
            ->keyBy('id');

        $items = [];
        foreach ($raw as $row) {
            $order = $orders->get($row['sales_order_id'] ?? null);
            if ($order === null) {
                return null;
            }
            $items[] = [
                'sales_order_id' => $order->id,
                'order_no' => $order->order_no,
                'customer_id' => $order->customer_id,
                'customer_name' => $order->customer?->name,
                'order_date' => $order->order_date?->format('Y-m-d'),
                'order_amount' => (float) $order->total_amount,
                'handover_amount' => round((float) ($row['handover_amount'] ?? 0), 2),
            ];
        }

        return $items;
    }

    private function filtered(Request $request)
    {
        $q = CashHandover::query();

        if (($start = trim((string) $request->input('start_date', ''))) !== '') {
            $q->whereDate('handover_date', '>=', $start);
        }
        if (($end = trim((string) $request->input('end_date', ''))) !== '') {
            $q->whereDate('handover_date', '<=', $end);
        }
        if (($no = trim((string) $request->input('no', ''))) !== '') {
            $q->where('handover_no', 'like', '%'.$no.'%');
        }
        if ($request->filled('salesman_id')) {
            $q->where('salesman_id', $request->input('salesman_id'));
        }
        if ($request->filled('account_id')) {
            $q->where('account_id', $request->input('account_id'));
        }
        if (in_array($request->input('status'), [CashHandover::STATUS_PENDING, CashHandover::STATUS_CONFIRMED], true)) {
            $q->where('status', $request->input('status'));
        }

        return $q;
    }

    /** 统计条：与列表同一筛选口径，不受分页影响 */
    private function stat(Request $request): array
    {
        $row = $this->filtered($request)
            ->selectRaw('COUNT(*) as cnt')
            ->selectRaw('COALESCE(SUM(amount), 0) as amount_sum')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END), 0) as confirmed_count")
            ->first();

        return [
            'count' => (int) $row->cnt,
            'amount_sum' => round((float) $row->amount_sum, 2),
            'pending_count' => (int) $row->pending_count,
            'confirmed_count' => (int) $row->confirmed_count,
        ];
    }

    private function transform(CashHandover $h, bool $withItems = false): array
    {
        $data = [
            'id' => $h->id,
            'no' => $h->handover_no,
            'date' => $h->handover_date?->format('Y-m-d'),
            'salesman_id' => $h->salesman_id,
            'salesman_name' => $h->salesman_name,
            'account_id' => $h->account_id,
            'account_name' => $h->account?->name,
            'amount' => (float) $h->amount,
            'status' => $h->status,
            'status_label' => CashHandover::statusLabel($h->status),
            'order_count' => isset($h->items_count) ? (int) $h->items_count : $h->items->count(),
            'remark' => $h->remark,
            'confirmed_name' => $h->confirmer?->real_name ?: $h->confirmer?->username,
            'confirmed_at' => $h->confirmed_at?->format('Y-m-d H:i:s'),
            'created_at' => $h->created_at?->format('Y-m-d H:i:s'),
        ];

        if ($withItems) {
            $data['items'] = $h->items->map(fn ($i) => [
                'id' => $i->id,
                'sales_order_id' => $i->sales_order_id,
                'order_no' => $i->order_no,
                'customer_id' => $i->customer_id,
                'customer_name' => $i->customer_name,
                'order_date' => $i->order_date?->format('Y-m-d'),
                'amount' => (float) $i->order_amount,
                'handover_amount' => (float) $i->handover_amount,
            ])->all();
        }

        return $data;
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
