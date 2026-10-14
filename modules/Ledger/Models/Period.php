<?php

namespace Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 会计期间（Period）
 *
 * status=open/closed。结账后该期间锁定，凭证不可增删改，期初余额与科目余额固化。
 * 反结账仅允许从最后一个已结账期间逆向操作。
 */
class Period extends Model
{
    protected $table = 'ledger_periods';

    protected $fillable = [
        'year', 'month', 'start_date', 'end_date', 'status',
        'closed_by', 'closed_by_name', 'closed_at',
        'voucher_count', 'total_debit', 'total_credit', 'check_report',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'closed_at' => 'datetime',
        'voucher_count' => 'integer',
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'check_report' => 'array',
    ];

    const STATUS_OPEN = 'open';

    const STATUS_CLOSED = 'closed';

    public function periodString(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_OPEN => '未结账',
            self::STATUS_CLOSED => '已结账',
            default => $status,
        };
    }
}
