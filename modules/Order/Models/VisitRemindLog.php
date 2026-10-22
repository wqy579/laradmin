<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Auth\Models\User;

/**
 * 业务员拜访提醒（T5.4）。
 *
 * 逾期没有独立字段：status=0 且 remind_date<today 就是逾期，
 * overdue()/isOverdue() 都按这一条规则算，不存在第二份真相。
 */
class VisitRemindLog extends Model
{
    protected $table = 'visit_remind_log';

    protected $fillable = [
        'visit_log_id', 'employee_id', 'customer_id', 'remind_date',
        'remind_type', 'status', 'sent_at', 'sent_by', 'remark', 'created_by',
    ];

    protected $casts = [
        'remind_date' => 'date',
        'sent_at' => 'datetime',
        'status' => 'integer',
    ];

    protected $appends = ['is_overdue'];

    /** 逾期=待提醒 且 应提醒日期已过（不含今天） */
    public function isOverdue(): bool
    {
        return (int) $this->status === 0
            && $this->remind_date !== null
            && $this->remind_date->lt(today());
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->isOverdue();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 0);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 0)->whereDate('remind_date', '<', today());
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function visitLog(): BelongsTo
    {
        return $this->belongsTo(VisitLog::class, 'visit_log_id');
    }
}
