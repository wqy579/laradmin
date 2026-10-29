<?php

namespace Modules\Archive\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 公司档案（法人主体）。
 *
 * 承载开票与收付款要素（纳税人识别号、开票名称、开户行账号），是单据上的主体维度。
 * is_default 全库至多一条为 true，由 CompanyController 在写入时维护——不用库级
 * 约束，是因为软删行仍占用唯一索引，部分唯一索引在 sqlite/MySQL 行为不一致。
 */
class Company extends Model
{
    use SoftDeletes;

    protected $table = 'archive_companies';

    protected $fillable = [
        'code', 'name', 'short_name', 'tax_no', 'invoice_title',
        'bank_name', 'bank_account', 'contact_name', 'contact_phone', 'address',
        'is_default', 'status', 'sort', 'remark',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'sort' => 'integer',
    ];

    public const STATUS_ENABLED = 'enabled';

    public const STATUS_DISABLED = 'disabled';

    public function divisions(): HasMany
    {
        return $this->hasMany(Division::class, 'company_id');
    }

    /** 只取启用中的公司 */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ENABLED);
    }
}
