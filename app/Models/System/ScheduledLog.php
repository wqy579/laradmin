<?php

namespace App\Models\System;

use App\Traits\ModelTrait;
use Illuminate\Database\Eloquent\Model;

class ScheduledLog extends Model
{
    use ModelTrait;

    protected $table = 'system_scheduled_log';

    const STATUS_RUNNING = 'running';
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_TIMEOUT = 'timeout';

    protected $fillable = [
        'scheduled_id',
        'status',
        'output',
        'error_message',
        'execution_time',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'execution_time' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function scheduled()
    {
        return $this->belongsTo(Scheduled::class, 'scheduled_id');
    }

    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_RUNNING => '执行中',
            self::STATUS_SUCCESS => '成功',
            self::STATUS_FAILED => '失败',
            self::STATUS_TIMEOUT => '超时',
        ];
    }
}
