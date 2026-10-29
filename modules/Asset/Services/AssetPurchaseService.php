<?php

namespace Modules\Asset\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Models\AssetCard;
use Modules\Asset\Models\AssetPurchase;
use Modules\Auth\Models\Department;
use Modules\Auth\Models\User;
use Modules\Ledger\Models\Subject;
use Modules\Ledger\Services\VoucherService;

/**
 * 购置单业务（D3）
 *
 * 三条硬规则收口在后端：
 *  1) 原值 = 数量×单价 由本服务重算，前端传值一律丢弃（只用于不一致时报错）；
 *  2) 保存成功后按数量生成 N 张资产卡片，单台原值 = 单价，净值 = 原值、累计折旧 = 0；
 *  3) 现金支出凭证只在勾选时生成，且凭证失败不回滚购置单本身。
 */
class AssetPurchaseService
{
    /** 固定资产原值科目 / 库存现金科目（LedgerSubjectSeeder 标准科目） */
    private const SUBJECT_FIXED_ASSET = '1601';

    private const SUBJECT_CASH = '1001';

    public function __construct(private VoucherService $voucherService) {}

    /**
     * 保存购置单 → 生成资产卡片 →（可选）登记现金支出凭证。
     *
     * 单号与卡片编号在同一事务内按当月流水号生成；凭证在事务外调用，
     * 因为 VoucherService::create 自带事务且期间已月结时会抛 DomainException，
     * 那种情况应当保留购置单并告知用户，而不是把整笔购置回滚。
     *
     * @return array{purchase: AssetPurchase, cards: int, voucher_id: ?int, voucher_error: ?string}
     */
    public function store(array $data, int $makerId, string $makerName): array
    {
        $quantity = (int) $data['quantity'];
        $unitPrice = round((float) $data['unit_price'], 2);
        $originalValue = round($quantity * $unitPrice, 2);

        // 前端展示的原值只作校验用：不一致说明有人改了前端算式或绕过了 UI，直接拒收。
        if (array_key_exists('original_value', $data) && $data['original_value'] !== null
            && abs((float) $data['original_value'] - $originalValue) > 0.009) {
            throw ValidationException::withMessages([
                'original_value' => "原值与数量×单价不符（应为 {$originalValue}），请刷新后重试",
            ]);
        }

        [$departmentId, $departmentName, $userId, $userName] = $this->resolveParties($data);

        $purchase = DB::transaction(function () use ($data, $quantity, $unitPrice, $originalValue, $departmentId, $departmentName, $userId, $userName, $makerId) {
            $purchase = AssetPurchase::create([
                'purchase_no' => $this->nextPurchaseNo($data['purchase_date']),
                'purchase_date' => $data['purchase_date'],
                'asset_name' => $data['asset_name'],
                'spec' => $data['spec'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'original_value' => $originalValue,
                'use_months' => (int) $data['use_months'],
                'residual_rate' => round((float) $data['residual_rate'], 2),
                'dep_method' => $data['dep_method'] ?? AssetPurchase::DEP_STRAIGHT,
                'department_id' => $departmentId,
                'department_name' => $departmentName,
                'user_id' => $userId,
                'user_name' => $userName,
                'supplier' => $data['supplier'] ?? null,
                'remark' => $data['remark'] ?? null,
                'status' => AssetPurchase::STATUS_NORMAL,
                'created_by' => $makerId ?: null,
            ]);

            $this->createCards($purchase, $quantity, $unitPrice);

            return $purchase;
        });

        $voucherId = null;
        $voucherError = null;
        if (! empty($data['register_voucher'])) {
            try {
                $voucher = $this->voucherService->create([
                    'voucher_date' => $purchase->purchase_date->toDateString(),
                    'status' => 'audited',
                    'auto_audit' => true,
                    'remark' => '固定资产购置：'.$purchase->purchase_no,
                    'entries' => $this->cashExpenseEntries($purchase),
                ], $makerId, $makerName);

                $voucherId = $voucher->id;
                $purchase->forceFill(['voucher_id' => $voucherId])->save();
            } catch (DomainException $e) {
                // 科目缺失 / 期间已月结：购置单保留，把原因回给用户补录
                $voucherError = $e->getMessage();
            }
        }

        return [
            'purchase' => $purchase->fresh(),
            'cards' => $quantity,
            'voucher_id' => $voucherId,
            'voucher_error' => $voucherError,
        ];
    }

    /**
     * 台账：卡片视图与列表视图共用本方法（同一分页接口），
     * 汇总与分页共用同一个 Builder 实例，条数与金额不可能口径不一。
     */
    public function ledger(array $filters, int $page, int $pageSize): array
    {
        $query = AssetCard::query()
            ->when($filters['start_date'] ?? null, fn ($q, $v) => $q->whereDate('acquire_date', '>=', $v))
            ->when($filters['end_date'] ?? null, fn ($q, $v) => $q->whereDate('acquire_date', '<=', $v))
            ->when($filters['keyword'] ?? null, function ($q, $v) {
                $q->where(function ($sub) use ($v) {
                    $sub->where('asset_no', 'like', "%{$v}%")->orWhere('asset_name', 'like', "%{$v}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v));

        $stat = (clone $query)
            ->selectRaw('count(*) as card_count, coalesce(sum(original_value), 0) as original_value, coalesce(sum(accumulated_dep), 0) as accumulated_dep, coalesce(sum(net_value), 0) as net_value')
            ->first();

        $pager = $query->orderByDesc('acquire_date')->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);

        return [
            'list' => $pager->items(),
            'total' => $pager->total(),
            'page' => $pager->currentPage(),
            'page_size' => $pager->perPage(),
            'last_page' => $pager->lastPage(),
            'summary' => [
                'count' => (int) $stat->card_count,
                'original_value' => round((float) $stat->original_value, 2),
                'accumulated_dep' => round((float) $stat->accumulated_dep, 2),
                'net_value' => round((float) $stat->net_value, 2),
            ],
        ];
    }

    /** 购置流水分页（右栏列表） */
    public function purchases(array $filters, int $page, int $pageSize)
    {
        return AssetPurchase::query()
            ->when($filters['start_date'] ?? null, fn ($q, $v) => $q->whereDate('purchase_date', '>=', $v))
            ->when($filters['end_date'] ?? null, fn ($q, $v) => $q->whereDate('purchase_date', '<=', $v))
            ->when($filters['purchase_no'] ?? null, fn ($q, $v) => $q->where('purchase_no', 'like', "%{$v}%"))
            ->when($filters['asset_name'] ?? null, fn ($q, $v) => $q->where('asset_name', 'like', "%{$v}%"))
            ->orderByDesc('purchase_date')->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    /** 数量=N → N 张卡片，编号按当月已有卡片数续号 */
    private function createCards(AssetPurchase $purchase, int $quantity, float $unitPrice): void
    {
        $period = $purchase->purchase_date->format('Ym');
        $seq = AssetCard::where('asset_no', 'like', 'ZC-'.$period.'-%')->count();

        $rows = [];
        $now = now();
        for ($i = 0; $i < $quantity; $i++) {
            $seq++;
            $rows[] = [
                'asset_no' => sprintf('ZC-%s-%04d', $period, $seq),
                'purchase_id' => $purchase->id,
                'asset_name' => $purchase->asset_name,
                'spec' => $purchase->spec,
                'original_value' => number_format($unitPrice, 2, '.', ''),
                'use_months' => $purchase->use_months,
                'residual_rate' => number_format($purchase->residual_rate, 2, '.', ''),
                'dep_method' => $purchase->dep_method,
                'accumulated_dep' => '0.00',
                'net_value' => number_format($unitPrice, 2, '.', ''),
                'department_id' => $purchase->department_id,
                'department_name' => $purchase->department_name,
                'user_id' => $purchase->user_id,
                'user_name' => $purchase->user_name,
                'status' => AssetCard::STATUS_INUSE,
                'acquire_date' => $purchase->purchase_date->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('asset_card')->insert($rows);
    }

    /**
     * 现金支出分录：借 固定资产，贷 库存现金。
     *
     * @return array<int, array<string, mixed>>
     */
    private function cashExpenseEntries(AssetPurchase $purchase): array
    {
        $summary = '购置'.$purchase->asset_name.'（'.$purchase->purchase_no.'）';
        $amount = (float) $purchase->original_value;

        return [
            $this->entry(self::SUBJECT_FIXED_ASSET, $summary, $amount, 'debit'),
            $this->entry(self::SUBJECT_CASH, $summary, $amount, 'credit'),
        ];
    }

    private function entry(string $code, string $summary, float $amount, string $direction): array
    {
        $subject = Subject::where('code', $code)->where('is_leaf', true)->first();
        if (! $subject) {
            throw new DomainException('未找到末级科目：'.$code.'，请先执行科目初始化');
        }

        return [
            'subject_id' => $subject->id,
            'summary' => $summary,
            'debit_amount' => $direction === 'debit' ? $amount : 0,
            'credit_amount' => $direction === 'credit' ? $amount : 0,
        ];
    }

    /**
     * 部门/使用人：允许只传 id，姓名由库里的主数据补齐，避免前端传什么就存什么。
     *
     * @return array{0:?int,1:?string,2:?int,3:?string}
     */
    private function resolveParties(array $data): array
    {
        $departmentId = ! empty($data['department_id']) ? (int) $data['department_id'] : null;
        $departmentName = $departmentId
            ? (Department::whereKey($departmentId)->value('name') ?: ($data['department_name'] ?? null))
            : null;

        $userId = ! empty($data['user_id']) ? (int) $data['user_id'] : null;
        $userName = null;
        if ($userId) {
            $user = User::whereKey($userId)->first();
            $userName = $user ? ($user->real_name ?: $user->username) : null;
        }

        return [$departmentId, $departmentName, $userId, $userName];
    }

    /** 单号 GZ-yyyymm-####，按月续号 */
    private function nextPurchaseNo(string $date): string
    {
        $period = date('Ym', strtotime($date));
        $seq = AssetPurchase::where('purchase_no', 'like', 'GZ-'.$period.'-%')->count() + 1;

        return sprintf('GZ-%s-%04d', $period, $seq);
    }
}
