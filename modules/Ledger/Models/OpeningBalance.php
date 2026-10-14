<?php

namespace Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 期初余额（OpeningBalance）
 *
 * subject_id+period 唯一。只有末级科目可录入，非末级由下级自动汇总。
 */
class OpeningBalance extends Model
{
    protected $table = 'ledger_opening_balances';

    protected $fillable = [
        'subject_id', 'period', 'opening_debit', 'opening_credit',
        'year_debit', 'year_credit',
    ];

    protected $casts = [
        'opening_debit' => 'decimal:2',
        'opening_credit' => 'decimal:2',
        'year_debit' => 'decimal:2',
        'year_credit' => 'decimal:2',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }
}
