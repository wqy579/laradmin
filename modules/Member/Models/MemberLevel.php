<?php

namespace Modules\Member\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MemberLevel extends Model
{
    protected $table = 'member_levels';

    protected $fillable = [
        'level_no', 'name', 'upgrade_amount', 'discount_rate', 'color',
        'description', 'sort', 'status', 'is_system',
    ];

    protected $casts = [
        'upgrade_amount' => 'float',
        'discount_rate' => 'float',
        'sort' => 'integer',
        'status' => 'integer',
        'is_system' => 'boolean',
    ];

    /** 当前启用等级下的会员数量（由控制器在列表查询里 withCount 附带） */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'level_id');
    }
}
