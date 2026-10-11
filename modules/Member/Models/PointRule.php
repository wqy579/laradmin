<?php

namespace Modules\Member\Models;

use Illuminate\Database\Eloquent\Model;

class PointRule extends Model
{
    protected $table = 'point_rules';

    protected $fillable = ['rule_key', 'rule_name', 'rule_value', 'description'];

    /** rule_value 是 JSON 字符串，自动解码为数组，便于前后端直接使用 */
    protected $casts = [
        'rule_value' => 'array',
    ];
}
