<?php

namespace Modules\Cash\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cash\Http\Requests\CashIntegralVerifyRequest;
use Modules\Cash\Models\CashIntegralVerify;
use Modules\Cash\Services\CashIntegralService;

/**
 * 积分核销控制器（D 域 D6，cash/integral/verifies 前缀）。
 *
 * 状态流转全部收口在 Service：编辑仅草稿、提交草稿→待审核、
 * 审核待审核→已审核（同事务扣减会员积分并写流水，幂等）、作废草稿/待审核→已作废。
 */
class CashIntegralVerifyController extends Controller
{
    use ResponseTrait;

    private const EXPORT_LIMIT = 10000;

    public function __construct(private CashIntegralService $service) {}

    public function index(Request $request): JsonResponse
    {
        $pager = $this->service->verifyList($request->all());

        return $this->success([
            'list' => collect($pager->items())->map(fn (CashIntegralVerify $v) => $this->transform($v))->values(),
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'last_page' => $pager->lastPage(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return $this->success($this->transform($this->service->verifyDetail($id), true));
    }

    /** 核销弹窗用：选中会员后的可用积分与累计收发 */
    public function memberSummary(Request $request): JsonResponse
    {
        $memberId = (int) $request->input('member_id');
        if ($memberId <= 0) {
            return $this->error('请选择会员', 422);
        }

        try {
            return $this->success($this->service->verifyMemberSummary($memberId));
        } catch (ModelNotFoundException $e) {
            return $this->error('会员不存在', 422);
        }
    }

    public function store(CashIntegralVerifyRequest $request): JsonResponse
    {
        $verify = $this->service->verifySave($request->validated(), null, $this->userId($request));

        return $this->created(['id' => $verify->id, 'verify_no' => $verify->verify_no], '保存成功');
    }

    /** 编辑：仅草稿 */
    public function update(CashIntegralVerifyRequest $request, int $id): JsonResponse
    {
        $verify = $this->find($id);

        if ((int) $verify->status !== CashIntegralVerify::STATUS_DRAFT) {
            return $this->error('只有草稿状态的核销单可以编辑', 422);
        }

        $verify = $this->service->verifySave($request->validated(), $verify, $this->userId($request));

        return $this->success(['id' => $verify->id, 'verify_no' => $verify->verify_no], '保存成功');
    }

    public function submit(int $id): JsonResponse
    {
        return $this->guard(fn () => $this->service->verifySubmit($this->find($id)), '提交成功');
    }

    public function audit(int $id, Request $request): JsonResponse
    {
        $operatorId = $this->userId($request);

        return $this->guard(fn () => $this->service->verifyAudit($id, $operatorId), '审核成功');
    }

    public function void(int $id): JsonResponse
    {
        return $this->guard(fn () => $this->service->verifyVoid($this->find($id)), '作废成功');
    }

    public function export(Request $request)
    {
        $verifies = $this->service->verifyRows($request->all(), self::EXPORT_LIMIT);

        $csv = "\u{FEFF}核销单号,核销日期,会员编号,会员名称,核销积分,核销后余额,类型,关联单号,状态,事由,制单人,审核时间\n";
        foreach ($verifies as $verify) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $verify->verify_no,
                $verify->verify_date?->format('Y-m-d'),
                $verify->member?->member_no ?? '',
                $this->csvCell($verify->member?->name),
                $verify->points,
                $verify->points_after ?? '',
                CashIntegralVerify::typeLabel((string) $verify->verify_type),
                $verify->related_order_no ?? '',
                CashIntegralVerify::statusLabel((int) $verify->status),
                $this->csvCell($verify->remark),
                $this->csvCell($verify->operator?->real_name),
                $verify->audited_at?->format('Y-m-d H:i:s') ?? ''
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="cash_integral_verifies.csv"',
        ]);
    }

    private function guard(callable $fn, string $message): JsonResponse
    {
        try {
            $verify = $fn();
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'id' => $verify->id,
            'verify_no' => $verify->verify_no,
            'status' => (int) $verify->status,
            'points_before' => $verify->points_before === null ? null : (int) $verify->points_before,
            'points_after' => $verify->points_after === null ? null : (int) $verify->points_after,
        ], $message);
    }

    private function find(int $id): CashIntegralVerify
    {
        return CashIntegralVerify::query()->findOrFail($id);
    }

    private function userId(Request $request): ?int
    {
        return optional($request->input('auth_user'))->id;
    }

    private function transform(CashIntegralVerify $verify, bool $withAudit = false): array
    {
        $row = [
            'id' => $verify->id,
            'verify_no' => $verify->verify_no,
            'verify_date' => $verify->verify_date?->format('Y-m-d'),
            'member_id' => (int) $verify->member_id,
            'member_no' => $verify->member?->member_no,
            'member_name' => $verify->member?->name,
            'points' => (int) $verify->points,
            'verify_type' => $verify->verify_type,
            'verify_type_label' => CashIntegralVerify::typeLabel((string) $verify->verify_type),
            'related_order_no' => $verify->related_order_no,
            'status' => (int) $verify->status,
            'status_label' => CashIntegralVerify::statusLabel((int) $verify->status),
            'points_before' => $verify->points_before === null ? null : (int) $verify->points_before,
            'points_after' => $verify->points_after === null ? null : (int) $verify->points_after,
            'remark' => $verify->remark,
            'operator_name' => $verify->operator?->real_name,
            'audited_at' => $verify->audited_at?->format('Y-m-d H:i:s'),
            'created_at' => $verify->created_at?->format('Y-m-d H:i:s'),
        ];

        if ($withAudit) {
            $row['settled_at'] = $verify->settled_at?->format('Y-m-d H:i:s');
        }

        return $row;
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
