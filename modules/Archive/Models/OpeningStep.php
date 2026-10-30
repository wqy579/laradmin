<?php

namespace Modules\Archive\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 开账向导的单个步骤（四步各一行）。
 *
 * 步骤条状态由后端下发而不是前端硬编码，见 OpeningWizardService::show()。
 */
class OpeningStep extends Model
{
    protected $table = 'archive_opening_steps';

    protected $fillable = [
        'wizard_id', 'step', 'key', 'title', 'status',
        'done_at', 'done_by', 'done_by_name', 'detail',
    ];

    protected $casts = [
        'step' => 'integer',
        'done_at' => 'datetime',
        'done_by' => 'integer',
        'detail' => 'array',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_DONE = 'done';

    public const STATUS_SKIPPED = 'skipped';

    /** 四步的定义：序号 => [key, 标题] */
    public const DEFS = [
        1 => ['subject', '科目设置'],
        2 => ['stock', '期初库存'],
        3 => ['balance', '期初余额'],
        4 => ['import', '导入/确认'],
    ];

    public function wizard(): BelongsTo
    {
        return $this->belongsTo(OpeningWizard::class, 'wizard_id');
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }
}
