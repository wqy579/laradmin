<?php

namespace Modules\Ledger\Models;

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
    ];

    const STATUS_DRAFT = 'draft';

    const STATUS_SUBMITTED = 'submitted';

    const STATUS_AUDITED = 'audited';

    const STATUS_REVERSED = 'reversed';

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
