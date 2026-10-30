<?php

namespace Modules\Archive\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 开账操作流水：完成步骤/重置步骤/导入/锁定/反锁定都留一行。
 *
 * 只追加，因此关掉自动 timestamps 只维护 created_at。
 */
class OpeningLog extends Model
{
    protected $table = 'archive_opening_logs';

    public $timestamps = false;

    protected $fillable = [
        'wizard_id', 'action', 'operator_id', 'operator_name', 'content', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public const ACTION_STEP_DONE = 'step_done';

    public const ACTION_STEP_RESET = 'step_reset';

    public const ACTION_IMPORT = 'import';

    public const ACTION_LOCK = 'lock';

    public const ACTION_UNLOCK = 'unlock';

    public function wizard(): BelongsTo
    {
        return $this->belongsTo(OpeningWizard::class, 'wizard_id');
    }
}
