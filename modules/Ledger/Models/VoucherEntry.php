<?php

namespace Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 凭证分录（VoucherEntry）
 *
 * 一行一条分录，借方/贷方互斥（同一行填了借方贷方置 0 反之亦然）。
 * 红字冲销用负数金额录入并允许。
 */
class VoucherEntry extends Model
{
    protected $table = 'ledger_voucher_entries';

    protected $fillable = [
        'voucher_id', 'line_no', 'subject_id', 'subject_code', 'subject_name',
        'aux_type', 'aux_id', 'aux_name',
        'brand_id', 'brand_name', 'summary',
        'debit_amount', 'credit_amount',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'brand_id' => 'integer',
        'debit_amount' => 'decimal:2',
        'credit_amount' => 'decimal:2',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }
}
