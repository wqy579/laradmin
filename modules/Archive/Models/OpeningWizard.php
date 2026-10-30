<?php

namespace Modules\Archive\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 开账向导头部。
 *
 * 一个「公司 + 事业部（可空）+ 期初期间」一份向导。唯一性由服务层保证，
 * 原因见 2026_11_05_000004 迁移的类注释（可空列建不了跨引擎唯一索引）。
 */
class OpeningWizard extends Model
{
    use SoftDeletes;

    protected $table = 'archive_opening_wizards';

    protected $fillable = [
        'company_id', 'division_id', 'period', 'status', 'step',
        'locked_at', 'locked_by', 'locked_by_name', 'lock_report', 'remark',
    ];

    protected $casts = [
        'step' => 'integer',
        'locked_at' => 'datetime',
        'locked_by' => 'integer',
        'lock_report' => 'array',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_LOCKED = 'locked';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'division_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(OpeningStep::class, 'wizard_id')->orderBy('step');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(OpeningStock::class, 'wizard_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(OpeningBalance::class, 'wizard_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(OpeningLog::class, 'wizard_id');
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }
}
