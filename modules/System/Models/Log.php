<?php

namespace Modules\System\Models;

use App\Traits\ModelTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Log extends Model
{
    use ModelTrait;
    protected $table = 'system_log';

    protected $fillable = [
        'user_id',
        'username',
        'module',
        'action',
        'method',
        'url',
        'ip',
        'user_agent',
        'params',
        'result',
        'status_code',
        'status',
        'error_message',
        'execution_time',
    ];

    protected $casts = [
        'params' => 'array',
        'execution_time' => 'integer',
        'status_code' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\Modules\Auth\Models\User::class, 'user_id');
    }
}
