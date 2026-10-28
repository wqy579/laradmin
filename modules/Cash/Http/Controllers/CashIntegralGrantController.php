<?php

namespace Modules\Cash\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cash\Http\Requests\CashIntegralGrantRequest;
use Modules\Cash\Models\CashIntegralGrant;
use Modules\Cash\Models\CashIntegralGrantItem;
use Modules\Cash\Services\CashIntegralService;
use Modules\Member\Models\Member;

/**
 * 积分发放控制器（D 域 D5，cash/integral/grants 前缀）。
 *
 * 状态流转全部收口在 Service：编辑仅草稿、提交草稿→待审核、
 * 审核待审核→已审核（同事务写会员积分与流水，幂等）、作废草稿/待审核→已作废。
 */
class CashIntegralGrantController extends Controller
{
    use ResponseTrait;

    private const EXPORT_LIMIT = 10000;

    public function __construct(private CashIntegralService $service) {}

    public function index(Request $request): JsonResponse
    {
        $pager = $this->service->grantList($request->all());

        $memberNames = $this->memberNames($pager->items());

        return $this->success([
            'list' => collect($pager->items())->map(fn (CashIntegralGrant $g) => $this->transform($g, $memberNames))->values(),
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'last_page' => $pager->lastPage(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $grant = $this->service->grantDetail($id);
        $memberNames = $this->memberNames([$grant]);

        $detail = $this->transform($grant, $memberNames);
        $detail['items'] = $grant->items->map(fn (CashIntegralGrantItem $item) => [
            'id' => $item->id,
            'member_id' => $item->member_id,
            'member_no' => $item->member?->member_no,
            'member_name' => $item->member?->name,
            'points' => (int) $item->points,
            'remark' => $item->remark,
            'settled_at' => $item->settled_at?->format('Y-m-d H:i:s'),
        ])->values();

        return $this->success($detail);
    }

    public function store(CashIntegralGrantRequest $request): JsonResponse
    {
        $grant = $this->service->grantSave($request->validated(), null, $this->userId($request));

        return $this->created(['id' => $grant->id, 'grant_no' => $grant->grant_no], '保存成功');
    }

    /** 编辑：仅草稿 */
    public function update(CashIntegralGrantRequest $request, int $id): JsonResponse
    {
        $grant = $this->find($id);

        if ((int) $grant->status !== CashIntegralGrant::STATUS_DRAFT) {
            return $this->error('只有草稿状态的发放单可以编辑', 422);
        }

        $grant = $this->service->grantSave($request->validated(), $grant, $this->userId($request));

        return $this->success(['id' => $grant->id, 'grant_no' => $grant->grant_no], '保存成功');
    }

    public function submit(int $id): JsonResponse
    {
        return $this->guard(fn () => $this->service->grantSubmit($this->find($id)), '提交成功');
    }

    public function audit(int $id, Request $request): JsonResponse
    {
        $operatorId = $this->userId($request);

        return $this->guard(fn () => $this->service->grantAudit($id, $operatorId), '审核成功');
    }

    public function void(int $id): JsonResponse
    {
        return $this->guard(fn () => $this->service->grantVoid($this->find($id)), '作废成功');
    }

    public function export(Request $request)
    {
        $grants = $this->service->grantRows($request->all(), self::EXPORT_LIMIT);
        $memberNames = $this->memberNames($grants->all());

        $items = CashIntegralGrantItem::query()
            ->whereIn('grant_id', $grants->pluck('id'))
            ->orderBy('grant_id')->orderBy('id')
            ->get()
            ->groupBy('grant_id');

        $csv = "\u{FEFF}发放单号,发放日期,类型,会员编号,会员名称,发放积分,状态,事由,制单人,审核时间\n";
        foreach ($grants as $grant) {
            $rows = $items->get($grant->id, collect());
            if ($rows->isEmpty()) {
                $rows = collect([null]);
            }
            foreach ($rows as $item) {
                $member = $item ? ($memberNames['members'][$item->member_id] ?? null) : null;
                $csv .= sprintf(
                    "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                    $grant->grant_no,
                    $grant->grant_date?->format('Y-m-d'),
                    CashIntegralGrant::typeLabel($grant->grant_type),
                    $member['member_no'] ?? '',
                    $this->csvCell($member['name'] ?? null),
                    $item?->points ?? '',
                    CashIntegralGrant::statusLabel((int) $grant->status),
                    $this->csvCell($grant->remark),
                    $this->csvCell($grant->operator?->real_name),
                    $grant->audited_at?->format('Y-m-d H:i:s') ?? ''
                );
            }
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="cash_integral_grants.csv"',
        ]);
    }

    private function guard(callable $fn, string $message): JsonResponse
    {
        try {
            $grant = $fn();
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(['id' => $grant->id, 'grant_no' => $grant->grant_no, 'status' => (int) $grant->status], $message);
    }

    private function find(int $id): CashIntegralGrant
    {
        return CashIntegralGrant::query()->findOrFail($id);
    }

    private function userId(Request $request): ?int
    {
        return optional($request->input('auth_user'))->id;
    }

    /**
     * 列表页会员信息：按发放单分组批量取明细会员，避免逐行查询。
     *
     * @return array{members: array<int, array{id:int, member_no:?string, name:?string}>, grants: array<int, array<int, int>>}
     */
    private function memberNames($grants): array
    {
        $items = CashIntegralGrantItem::query()
            ->whereIn('grant_id', collect($grants)->pluck('id'))
            ->orderBy('id')
            ->get(['grant_id', 'member_id']);

        if ($items->isEmpty()) {
            return ['members' => [], 'grants' => []];
        }

        $members = Member::query()
            ->whereIn('id', $items->pluck('member_id')->unique())
            ->get(['id', 'member_no', 'name'])
            ->mapWithKeys(fn (Member $m) => [$m->id => ['id' => $m->id, 'member_no' => $m->member_no, 'name' => $m->name]])
            ->all();

        $grantsMap = $items->groupBy('grant_id')->map(fn ($rows) => $rows->pluck('member_id')->unique()->values()->all())->all();

        return ['members' => $members, 'grants' => $grantsMap];
    }

    private function transform(CashIntegralGrant $grant, array $memberNames): array
    {
        $names = collect($memberNames['grants'][$grant->id] ?? [])
            ->map(fn ($id) => $memberNames['members'][$id]['name'] ?? null)
            ->filter()
            ->unique()
            ->values();

        return [
            'id' => $grant->id,
            'grant_no' => $grant->grant_no,
            'grant_date' => $grant->grant_date?->format('Y-m-d'),
            'grant_type' => $grant->grant_type,
            'grant_type_label' => CashIntegralGrant::typeLabel($grant->grant_type),
            'total_points' => (int) $grant->total_points,
            'status' => (int) $grant->status,
            'status_label' => CashIntegralGrant::statusLabel((int) $grant->status),
            'member_names' => $names->implode('、'),
            'member_count' => $names->count(),
            'remark' => $grant->remark,
            'operator_name' => $grant->operator?->real_name,
            'audited_at' => $grant->audited_at?->format('Y-m-d H:i:s'),
            'created_at' => $grant->created_at?->format('Y-m-d H:i:s'),
        ];
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
