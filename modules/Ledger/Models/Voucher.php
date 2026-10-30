<?php

namespace Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User;

/**
 * 记账凭证（Voucher）
 *
 * 有借必有贷、借贷必相等。status=draft/submitted/audited/reversed。
 * 作废（reversed）的凭证保留编号不回收，显示作废红章。
 * voucher_no 在同一会计期间内连续不断号。
 */
class Voucher extends Model
{
    protected $table = 'ledger_vouchers';

    protected $fillable = [
        'voucher_no', 'voucher_date', 'period', 'attachments_count', 'attachments',
        'total_debit', 'total_credit', 'status',
        'maker_id', 'maker_name', 'auditor_id', 'auditor_name',
        'audited_at', 'remark',
        'red_of_id', 'is_red', 'auto_audit',
        'source_type', 'source_id', 'source_seq',
    ];

    protected $casts = [
        'voucher_date' => 'date',
        'audited_at' => 'datetime',
        'attachments_count' => 'integer',
        'attachments' => 'array',
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'is_red' => 'boolean',
        'auto_audit' => 'boolean',
        'source_id' => 'integer',
        'source_seq' => 'integer',
    ];

    const STATUS_DRAFT = 'draft';

    const STATUS_SUBMITTED = 'submitted';

    const STATUS_AUDITED = 'audited';

    const STATUS_REVERSED = 'reversed';

    /** 来源单据类型：销售出库/收款/付款/费用/库存调整（QA-05 业务单据生成凭证） */
    const SOURCE_DELIVERY = 'delivery';

    const SOURCE_RECEIVE = 'receive';

    const SOURCE_PAY = 'pay';

    const SOURCE_EXPENSE = 'expense';

    const SOURCE_STOCK_ADJUST = 'stock_adjust';

    /** 全部可生成的来源单据类型（也是生成器分发的键集合） */
    public const SOURCE_TYPES = [
        self::SOURCE_DELIVERY,
        self::SOURCE_RECEIVE,
        self::SOURCE_PAY,
        self::SOURCE_EXPENSE,
        self::SOURCE_STOCK_ADJUST,
    ];

    /** 按来源单据 + 序号取某一张凭证（生成器的幂等查询） */
    public static function findBySource(string $sourceType, int $sourceId, int $seq = 1): ?self
    {
        return static::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('source_seq', $seq)
            ->first();
    }

    /** 某张来源单据已生成的全部凭证（按 seq 升序） */
    public static function listBySource(string $sourceType, int $sourceId): EloquentCollection
    {
        return static::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->orderBy('source_seq')
            ->get();
    }

    public function entries(): HasMany
    {
        return $this->hasMany(VoucherEntry::class, 'voucher_id')->orderBy('line_no');
    }

    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maker_id');
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auditor_id');
    }

    /** 红冲单所属的原凭证 */
    public function reversal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'red_of_id');
    }

    /** 原凭证被生成的红冲单 */
    public function redReversals(): HasMany
    {
        return $this->hasMany(self::class, 'red_of_id');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_DRAFT => '草稿',
            self::STATUS_SUBMITTED => '待审核',
            self::STATUS_AUDITED => '已审核',
            self::STATUS_REVERSED => '已作废',
            default => $status,
        };
    }
}
