<?php

namespace Modules\Archive\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 开账期初余额暂存行（第 3 步）。
 *
 * 与 Modules\Ledger\Models\OpeningBalance（真实期初表）同名不同命名空间：
 * 本表是开账草稿，那张是总账期初。服务层同时用到两者，Ledger 那张以别名引入，
 * 见 OpeningWizardService。
 */
class OpeningBalance extends Model
{
    protected $table = 'archive_opening_balances';

    protected $fillable = [
        'wizard_id', 'subject_id', 'period',
        'opening_debit', 'opening_credit', 'year_debit', 'year_credit',
    ];

    protected $casts = [
        'opening_debit' => 'decimal:2',
        'opening_credit' => 'decimal:2',
        'year_debit' => 'decimal:2',
        'year_credit' => 'decimal:2',
    ];

    public function wizard(): BelongsTo
    {
        return $this->belongsTo(OpeningWizard::class, 'wizard_id');
    }
}
