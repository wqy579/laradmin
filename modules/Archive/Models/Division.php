<?php

namespace Modules\Archive\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 事业部档案。
 *
 * 事业部挂在法人公司之下（一司多部），是绩效/提成的组织汇总维度。
 * company_name 不落库快照——事业部允许改名且档案页要显示当前公司名，
 * 列表走 with('company') 一次联取。
 */
class Division extends Model
{
    use SoftDeletes;

    protected $table = 'archive_divisions';

    protected $fillable = [
        'company_id', 'code', 'name',
        'manager_name', 'manager_phone', 'effective_at',
        'status', 'sort', 'remark',
    ];

    protected $casts = [
        'effective_at' => 'date:Y-m-d',
        'sort' => 'integer',
    ];

    public const STATUS_ENABLED = 'enabled';

    public const STATUS_DISABLED = 'disabled';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** 只取启用中的事业部 */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ENABLED);
    }
}
