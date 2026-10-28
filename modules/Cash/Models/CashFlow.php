<?php

namespace Modules\Cash\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 账户收支流水（CashFlow）
 *
 * amount 恒为正数，收支方向看 direction；余额不在表里存，
 * 由 CashFlowService 按账户顺序逐行累计得出（避免多处写入时余额漂移）。
 */
class CashFlow extends Model
{
    protected $table = 'cash_flow';

    protected $fillable = [
        'account_id', 'flow_no', 'flow_date', 'direction', 'summary',
        'amount', 'related_type', 'related_id', 'created_by',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'flow_date' => 'date',
        'amount' => 'decimal:2',
        'related_id' => 'integer',
        'created_by' => 'integer',
    ];

    const DIRECTION_INCOME = 'income';

    const DIRECTION_EXPENSE = 'expense';

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'account_id');
    }

    public static function directionLabel(string $direction): string
    {
        return $direction === self::DIRECTION_INCOME ? '收入' : '支出';
    }
}
