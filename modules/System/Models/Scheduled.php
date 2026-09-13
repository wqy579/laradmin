<?php

namespace Modules\System\Models;

use App\Traits\ModelTrait;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Scheduled extends Model
{
    use ModelTrait, SoftDeletes;

    protected $table = 'system_scheduled';

    const STATUS_IDLE = 'idle';
    const STATUS_RUNNING = 'running';
    const STATUS_PAUSED = 'paused';
    const STATUS_STOPPED = 'stopped';
    const STATUS_ERROR = 'error';

    const TYPE_ARTISAN = 'artisan';
    const TYPE_JOB = 'job';
    const TYPE_SHELL = 'shell';

    protected $fillable = [
        'name',
        'command',
        'description',
        'type',
        'expression',
        'interval',
        'timezone',
        'status',
        'timeout',
        'without_overlapping',
        'max_tries',
        'parameters',
        'last_run_at',
        'next_run_at',
        'started_at',
        'run_count',
        'error_count',
    ];

    protected $casts = [
        'timeout' => 'integer',
        'without_overlapping' => 'boolean',
        'max_tries' => 'integer',
        'parameters' => 'array',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'started_at' => 'datetime',
        'run_count' => 'integer',
        'error_count' => 'integer',
        'interval' => 'integer',
    ];

    private static array $transitions = [
        self::STATUS_IDLE => [self::STATUS_RUNNING, self::STATUS_STOPPED],
        self::STATUS_RUNNING => [self::STATUS_IDLE, self::STATUS_PAUSED, self::STATUS_STOPPED, self::STATUS_ERROR],
        self::STATUS_PAUSED => [self::STATUS_RUNNING, self::STATUS_STOPPED],
        self::STATUS_STOPPED => [self::STATUS_RUNNING],
        self::STATUS_ERROR => [self::STATUS_IDLE, self::STATUS_RUNNING],
    ];

    public static array $activeStatuses = [self::STATUS_IDLE, self::STATUS_RUNNING];

    public function logs()
    {
        return $this->hasMany(ScheduledLog::class, 'scheduled_id');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::$transitions[$this->status] ?? []);
    }

    /**
     * 是否使用固定间隔模式
     */
    public function isIntervalMode(): bool
    {
        return !empty($this->interval);
    }

    /**
     * 检查任务是否到期运行
     */
    public function isDue(): bool
    {
        if ($this->isIntervalMode()) {
            if (!$this->last_run_at) {
                return true;
            }
            return $this->last_run_at->addSeconds($this->interval)->isPast();
        }

        try {
            $cron = new CronExpression($this->expression);
            return $cron->isDue('now', $this->timezone);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 计算下次运行时间
     */
    public function calculateNextRunAt(): ?\DateTime
    {
        if ($this->isIntervalMode()) {
            $base = $this->last_run_at ?? now();
            return $base->addSeconds($this->interval)->toDateTime();
        }

        try {
            $cron = new CronExpression($this->expression);
            return $cron->getNextRunDate('now', 0, false, $this->timezone);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function isOverdue(): bool
    {
        if (!$this->started_at || $this->status !== self::STATUS_RUNNING) {
            return false;
        }
        return $this->started_at->addSeconds($this->timeout)->isPast();
    }

    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_IDLE => '空闲',
            self::STATUS_RUNNING => '运行中',
            self::STATUS_PAUSED => '已暂停',
            self::STATUS_STOPPED => '已禁用',
            self::STATUS_ERROR => '异常',
        ];
    }

    public static function getTypeOptions(): array
    {
        return [
            self::TYPE_ARTISAN => 'Artisan命令',
            self::TYPE_JOB => '队列任务',
            self::TYPE_SHELL => 'Shell命令',
        ];
    }
}
