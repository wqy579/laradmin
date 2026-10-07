<?php

namespace Modules\Stock\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 库存调整单主表
 *
 * 状态机：draft → pending → approved
 *                      ↑______| (reject 回到 draft)
 * draft 可编辑/删除/提交；pending 可审核/驳回/取消；approved/cancelled 只读。
 */
class StockAdjust extends Model
{
    protected $table = 'stock_adjusts';

    protected $fillable = [
        'adjust_no', 'warehouse_id', 'adjust_date', 'adjust_type', 'status',
        'total_qty', 'total_amount', 'reason',
        'created_by', 'creator_name',
        'approved_by', 'approver_name', 'approved_at', 'approval_comment',
    ];

    protected $casts = [
        'adjust_date' => 'date',
        'approved_at' => 'datetime',
        'total_qty' => 'integer',
        'total_amount' => 'float',
    ];

    const STATUS_DRAFT = 'draft';

    const STATUS_PENDING = 'pending';

    const STATUS_APPROVED = 'approved';

    const STATUS_CANCELLED = 'cancelled';

    const TYPE_STOCK_LOSS = 'stock_loss';

    const TYPE_STOCK_GAIN = 'stock_gain';

    const TYPE_OTHER = 'other';

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustItem::class, 'adjust_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
