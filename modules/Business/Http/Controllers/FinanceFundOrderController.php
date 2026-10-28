<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Business\Services\FinanceFundService;

/**
 * B4 资金单族（6 类）统一控制器：欠条确认 / 借款还款 / 资金增减 /
 * 应收应付调整 / 客户余额调整 / 供应商余额调整。
 *
 * 6 类共用一套 CRUD + 审核动作，类型由路由 {type} 判别列承载。
 */
class FinanceFundOrderController extends Controller
{
    use ResponseTrait;

    public function __construct(private FinanceFundService $service) {}

    public function index(Request $request, string $type)
    {
        if ($error = $this->guardType($type)) {
            return $error;
        }

        $filters = array_filter([
            'status' => $request->input('status'),
            'partner_id' => $request->input('partner_id'),
            'keyword' => $request->input('keyword'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'direction' => $request->input('direction'),
        ], fn ($v) => $v !== null && $v !== '');

        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(200, max(1, (int) $request->input('page_size', 20)));

        return $this->success($this->service->list($type, $filters, $page, $pageSize));
    }

    public function show(string $type, int $id)
    {
        if ($error = $this->guardType($type)) {
            return $error;
        }

        try {
            return $this->success($this->service->detail($type, $id));
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function store(Request $request, string $type)
    {
        if ($error = $this->guardType($type)) {
            return $error;
        }

        $validated = $request->validate($this->rules($type));

        try {
            return $this->created($this->service->create($type, $validated, $this->makerId($request)));
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function confirm(string $type, int $id)
    {
        if ($error = $this->guardType($type)) {
            return $error;
        }

        try {
            return $this->success($this->service->confirm($type, $id));
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function approve(Request $request, string $type, int $id)
    {
        if ($error = $this->guardType($type)) {
            return $error;
        }

        try {
            return $this->success($this->service->approve($type, $id, $this->makerId($request)));
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function reject(Request $request, string $type, int $id)
    {
        if ($error = $this->guardType($type)) {
            return $error;
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        try {
            return $this->success($this->service->reject($type, $id, $validated['reason']));
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function repay(Request $request, string $type, int $id)
    {
        if ($error = $this->guardType($type)) {
            return $error;
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'doc_date' => 'nullable|date',
            'payment_method' => 'nullable|string|max:50',
            'abstract' => 'nullable|string|max:255',
        ]);

        try {
            return $this->success($this->service->repay($type, $id, $validated, $this->makerId($request)));
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** 建单字段：6 类共用主表，按类型收紧必填项 */
    private function rules(string $type): array
    {
        $rules = [
            'partner_type' => 'nullable|string|in:customer,supplier,employee',
            'partner_id' => 'nullable|integer|min:1',
            'partner_name' => 'nullable|string|max:100',
            'amount' => 'required|numeric',
            'doc_date' => 'nullable|date',
            'abstract' => 'nullable|string|max:255',
            'items' => 'nullable|array',
        ];

        if ($type === FinanceFundService::TYPE_IOU) {
            $rules['amount'] = 'required|numeric|gt:0';
            $rules['due_date'] = 'required|date';
            $rules['signature_url'] = 'required|string|max:255';
        }

        if ($type === FinanceFundService::TYPE_BORROW_REPAY) {
            $rules['amount'] = 'required|numeric|gt:0';
            $rules['due_date'] = 'nullable|date';
            $rules['payment_method'] = 'nullable|string|max:50';
        }

        if ($type === FinanceFundService::TYPE_FUND_IO) {
            $rules['amount'] = 'required|numeric|not_in:0';
            $rules['direction'] = 'required|string|in:increase,decrease';
            $rules['account'] = 'nullable|string|max:50';
            $rules['payment_method'] = 'nullable|string|max:50';
        }

        if ($type === FinanceFundService::TYPE_ARAP_ADJUST) {
            $rules['reason'] = 'required|string|max:255';
            $rules['partner_id'] = 'required|integer|min:1';
            $rules['partner_type'] = 'required|string|in:customer,supplier';
            $rules['items'] = 'required|array|min:1';
            $rules['items.*.subject'] = 'required|string|max:100';
            $rules['items.*.subject_code'] = 'required|string|max:30';
            $rules['items.*.direction'] = 'required|string|in:借,贷';
            $rules['items.*.amount'] = 'required|numeric|gt:0';
        }

        if (in_array($type, [FinanceFundService::TYPE_CUSTOMER_BALANCE, FinanceFundService::TYPE_SUPPLIER_BALANCE], true)) {
            $rules['reason'] = 'required|string|max:255';
            $rules['partner_id'] = 'required|integer|min:1';
            $rules['partner_name'] = 'required|string|max:100';
            $rules['amount'] = 'required|numeric|not_in:0';
        }

        return $rules;
    }

    private function guardType(string $type)
    {
        if (isset(FinanceFundService::DOC_TYPE_MAP[$type])) {
            return null;
        }

        return $this->error('未知单据类型：'.$type, 422);
    }

    private function makerId(Request $request): int
    {
        $user = $request->input('auth_user');

        return (int) ($user?->id ?? 0);
    }
}
