<?php

namespace Modules\Stock\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 盘点单主表
 *
 * 状态机：draft(待盘点) → in_progress(盘点中) → pending(待审核) → approved(已审核)
 *                              ↑_______________| (驳回 reject 回到 in_progress)
 * draft/in_progress 可删除、可取消；approved 后库存与财务不可回改（如需冲销走红冲，本期未实现）。
 */
class StockCheck extends Model
{
    protected $table = 'stock_checks';

    protected $fillable = [
        'check_no', 'warehouse_id', 'check_date', 'check_type', 'status',
        'total_skus', 'profit_qty', 'loss_qty', 'profit_amount', 'loss_amount',
        'remark', 'created_by', 'creator_name', 'approved_by', 'approver_name',
        'approved_at', 'approval_comment',
    ];

    protected $casts = [
        'check_date' => 'date',
        'approved_at' => 'datetime',
    ];

    const STATUS_DRAFT = 'draft';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_CANCELLED = 'cancelled';

    public function items(): HasMany
    {
        return $this->hasMany(StockCheckItem::class, 'check_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
