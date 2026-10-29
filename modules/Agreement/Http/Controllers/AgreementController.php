<?php

namespace Modules\Agreement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Agreement\Models\Agreement;
use Modules\Agreement\Services\AgreementService;
use Modules\Order\Models\Customer;
use Modules\Report\Support\ReportFilter;

/**
 * 协议管理 + 陈列协议统计控制器（F4，agr/agreements 前缀）
 *
 * 三态：生效中 / 已到期 / 已终止。到期状态在每次查询前惰性刷新，
 * 终止为人工终态动作；兑现率 = 已兑现 / 协议额（服务端计算）。
 */
class AgreementController extends Controller
{
    use ResponseTrait;

    public function __construct(private AgreementService $service) {}

    public function options(): JsonResponse
    {
        $customers = Customer::query()
            ->orderBy('id')
            ->limit(2000)
            ->get(['id', 'name'])
            ->map(fn (Customer $c) => ['id' => $c->id, 'name' => $c->name])
            ->all();

        return $this->success(['customers' => $customers]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->service->refreshExpired();

        $filters = $this->filters($request);
        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(200, max(1, (int) $request->input('page_size', 20)));

        $pager = $this->service->paginate($filters, $page, $pageSize);

        return $this->success([
            'list' => collect($pager->items())->map(fn (Agreement $a) => $this->service->transform($a, true))->values(),
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'stat' => $this->service->stat($filters),
        ]);
    }

    /** 陈列协议统计：兑现率进度条列表 + 顶部聚合 */
    public function stats(Request $request): JsonResponse
    {
        $this->service->refreshExpired();

        return $this->success($this->service->displayStats($this->filters($request)));
    }

    public function show(int $id): JsonResponse
    {
        $agreement = Agreement::query()->findOrFail($id);

        return $this->success($this->service->transform($agreement, true));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        try {
            $agreement = $this->service->create($data, $request->user()->real_name ?? $request->user()->username ?? null);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->service->transform($agreement, true), '协议已创建');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $agreement = Agreement::query()->findOrFail($id);
        $data = $this->validatePayload($request, true);

        try {
            $agreement = $this->service->update($agreement, $data);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->service->transform($agreement, true), '已保存');
    }

    /** 终止（终态） */
    public function terminate(Request $request, int $id): JsonResponse
    {
        $agreement = Agreement::query()->findOrFail($id);
        $data = $request->validate(['terminated_date' => ['sometimes', 'date']]);

        try {
            $agreement = $this->service->terminate($agreement, $data['terminated_date'] ?? null);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->service->transform($agreement, true), '协议已终止');
    }

    /** 登记兑现（增量） */
    public function redeem(Request $request, int $id): JsonResponse
    {
        $agreement = Agreement::query()->findOrFail($id);
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0']]);

        try {
            $agreement = $this->service->redeem($agreement, (float) $data['amount']);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->service->transform($agreement, true), '兑现已登记');
    }

    public function destroy(int $id): JsonResponse
    {
        $agreement = Agreement::query()->findOrFail($id);

        if ((float) $agreement->redeemed_amount > 0) {
            return $this->error('已有兑现记录，不能删除', 422);
        }

        $agreement->delete();

        return $this->success(null, '已删除');
    }

    public function export(Request $request)
    {
        $this->service->refreshExpired();

        $rows = [];
        foreach ($this->service->query($this->filters($request))->orderByDesc('start_date')->limit(50000)->get() as $a) {
            $rows[] = [
                $a->agreement_no, $a->title, $a->customer_name, Agreement::typeLabel($a->type),
                (string) $a->start_date, (string) $a->end_date,
                number_format((float) $a->amount, 2, '.', ''),
                number_format((float) $a->redeemed_amount, 2, '.', ''),
                $a->redemptionRate().'%',
                Agreement::statusLabel($a->status),
            ];
        }

        $headers = ['协议编号', '协议名称', '客户', '类型', '生效日期', '到期日期', '协议额', '已兑现额', '兑现率', '状态'];
        $csv = ReportFilter::csv('协议档案', $headers, $rows);

        return ReportFilter::csvResponse($csv, 'agreements_'.date('Ymd').'.csv');
    }

    private function filters(Request $request): array
    {
        return array_filter([
            'keyword' => $request->input('keyword'),
            'customer_id' => $request->input('customer_id'),
            'type' => $request->input('type'),
            'status' => $request->input('status'),
            'start_from' => $request->input('start_from'),
            'start_to' => $request->input('start_to'),
            'page' => $request->input('page'),
            'page_size' => $request->input('page_size'),
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:100'],
            'customer_id' => [$required, 'nullable', 'integer'],
            'customer_name' => [$required, 'string', 'max:100'],
            'type' => [$required, 'in:display,general'],
            'start_date' => [$required, 'date'],
            'end_date' => [$required, 'date', 'after_or_equal:start_date'],
            'amount' => [$required, 'numeric', 'min:0'],
            'remark' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
    }
}
