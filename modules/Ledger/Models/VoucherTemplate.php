<?php

namespace Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;

/**
 * 凭证模板（VoucherTemplate）
 *
 * 可把常用凭证存为模板，调入时复刻分录结构。本期只做简单的保存模板/调入模板。
 */
class VoucherTemplate extends Model
{
    protected $table = 'ledger_voucher_templates';

    protected $fillable = [
        'name', 'template_data', 'creator_id', 'creator_name',
    ];

    protected $casts = [
        'template_data' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
