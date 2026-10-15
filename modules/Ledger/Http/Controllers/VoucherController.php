<?php

namespace Modules\Ledger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Ledger\Models\Voucher;
use Modules\Ledger\Services\VoucherService;

/**
 * 记账凭证控制器：录入/编辑/审核/反审核/作废/查询/模板/明细账。
 */
class VoucherController extends Controller
{
    use ResponseTrait;

    public function __construct(private VoucherService $service) {}

    public function index(Request $request)
    {
        $filters = $request->only([
            'voucher_no', 'status', 'start_date', 'end_date', 'period',
            'subject_id', 'maker_name', 'auditor_name',
            'min_amount', 'max_amount', 'direction',
        ]);
        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('page_size', 20);

        return $this->success($this->service->list($filters, $page, $pageSize));
    }

    public function show(int $id)
    {
        return $this->success(['voucher' => $this->service->detail($id)]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'voucher_date' => 'required|date',
            'attachments_count' => 'nullable|integer|min:0',
            'remark' => 'nullable|string',
            'status' => 'nullable|in:draft,submitted',
            'entries' => 'required|array|min:1',
            'entries.*.subject_id' => 'required|exists:ledger_subjects,id',
            'entries.*.aux_type' => 'nullable|string',
            'entries.*.aux_id' => 'nullable|integer',
            'entries.*.aux_name' => 'nullable|string',
            'entries.*.brand_id' => 'nullable|integer',
            'entries.*.brand_name' => 'nullable|string',
            'entries.*.summary' => 'nullable|string|max:200',
            'entries.*.debit_amount' => 'nullable|numeric',
            'entries.*.credit_amount' => 'nullable|numeric',
        ]);

        $user = $request->input('auth_user');
        $makerId = $user?->id ?? 0;
        $makerName = $user?->real_name ?? $user?->username ?? '系统';

        try {
            $voucher = $this->service->create($validated, $makerId, $makerName);
            $msg = ($validated['status'] ?? '') === Voucher::STATUS_SUBMITTED ? '提交成功' : '保存成功';

            return $this->success(['voucher' => $this->service->detail($voucher->id)], $msg);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('保存失败：'.$e->getMessage(), 500);
        }
    }

    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'voucher_date' => 'sometimes|date',
            'attachments_count' => 'nullable|integer|min:0',
            'remark' => 'nullable|string',
            'status' => 'nullable|in:draft,submitted',
            'entries' => 'sometimes|array|min:1',
            'entries.*.subject_id' => 'required_with:entries|exists:ledger_subjects,id',
            'entries.*.aux_type' => 'nullable|string',
            'entries.*.aux_id' => 'nullable|integer',
            'entries.*.aux_name' => 'nullable|string',
            'entries.*.brand_id' => 'nullable|integer',
            'entries.*.brand_name' => 'nullable|string',
            'entries.*.summary' => 'nullable|string|max:200',
            'entries.*.debit_amount' => 'nullable|numeric',
            'entries.*.credit_amount' => 'nullable|numeric',
        ]);

        try {
            $voucher = $this->service->update($id, $validated);
            $msg = ($validated['status'] ?? '') === Voucher::STATUS_SUBMITTED ? '提交成功' : '保存成功';

            return $this->success(['voucher' => $this->service->detail($voucher->id)], $msg);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('保存失败：'.$e->getMessage(), 500);
        }
    }

    public function audit(Request $request, int $id)
    {
        $user = $request->input('auth_user');

        try {
            $this->service->auditOne($id, $user?->id ?? 0, $user?->real_name ?? $user?->username ?? '系统');

            return $this->success(null, '审核成功');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('审核失败：'.$e->getMessage(), 500);
        }
    }

    public function batchAudit(Request $request)
    {
        $validated = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer']);
        $user = $request->input('auth_user');

        $result = $this->service->audit($validated['ids'], $user?->id ?? 0, $user?->real_name ?? $user?->username ?? '系统');
        if (! empty($result['failed'])) {
            return $this->error('部分凭证审核失败', 422, $result);
        }

        return $this->success($result, '批量审核成功');
    }

    public function batchReverse(Request $request)
    {
        $validated = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer']);
        $failed = [];
        $success = 0;
        foreach ($validated['ids'] as $id) {
            try {
                $this->service->reverse((int) $id);
                $success++;
            } catch (\Throwable $e) {
                $failed[] = ['id' => $id, 'message' => $e->getMessage()];
            }
        }
        if (! empty($failed)) {
            return $this->error('部分凭证作废失败', 422, ['success' => $success, 'failed' => $failed]);
        }

        return $this->success(['success' => $success], '批量作废成功');
    }

    public function reverseAudit(Request $request, int $id)
    {
        try {
            $this->service->reverseAudit($id);

            return $this->success(null, '反审核成功');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('反审核失败：'.$e->getMessage(), 500);
        }
    }

    public function reverse(int $id)
    {
        try {
            $this->service->reverse($id);

            return $this->success(null, '作废成功');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('作废失败：'.$e->getMessage(), 500);
        }
    }

    public function destroy(int $id)
    {
        try {
            $this->service->delete($id);

            return $this->success(null, '删除成功');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('删除失败：'.$e->getMessage(), 500);
        }
    }

    /** 明细账（按科目+期间） */
    public function subjectLedger(Request $request)
    {
        $validated = $request->validate([
            'subject_id' => 'required|exists:ledger_subjects,id',
            'period' => 'required|string|size:7',
        ]);

        return $this->success($this->service->subjectLedger($validated['subject_id'], $validated['period']));
    }

    /** 模板列表 */
    public function templates()
    {
        return $this->success(['templates' => $this->service->templates()]);
    }

    /** 保存模板 */
    public function saveTemplate(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:80',
            'entries' => 'required|array',
        ]);
        $user = $request->input('auth_user');

        $template = $this->service->saveTemplate(
            $validated['name'],
            $validated['entries'],
            $user?->id ?? 0,
            $user?->real_name ?? $user?->username ?? '系统'
        );

        return $this->success(['template' => ['id' => $template->id, 'name' => $template->name]], '模板保存成功');
    }

    public function export(Request $request)
    {
        $filters = $request->only(['voucher_no', 'status', 'start_date', 'end_date', 'period', 'subject_id']);
        $data = $this->service->list($filters, 1, 10000);
        $csv = "\u{FEFF}凭证字号,凭证日期,期间,摘要,借方合计,贷方合计,制单人,审核人,状态\n";
        foreach ($data['list'] as $v) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $this->csvCell($v['voucher_no']),
                $v['voucher_date'],
                $v['period'],
                $this->csvCell($v['summary'] ?? ''),
                number_format((float) $v['total_debit'], 2, '.', ''),
                number_format((float) $v['total_credit'], 2, '.', ''),
                $this->csvCell($v['maker_name'] ?? ''),
                $this->csvCell($v['auditor_name'] ?? ''),
                $v['status_label']
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="ledger_vouchers.csv"',
        ]);
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
