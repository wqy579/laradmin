<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 预售/订货调整单（PADJ）：对已创建的销售订单明细做数量/单价调整，留痕可查。
 *
 * 纯台账型：只记录调整前后对照，不动原销售单（原单保持原值，报表可对比）。
 * status: draft（可编辑/可删） → confirmed（confirm 后固化，adjusted_by/adjusted_at 落库）。
 */
class PresaleAdjust extends Model
{
    protected $table = 'presale_adjust';

    protected $fillable = [
        'adjust_no', 'sales_order_id', 'customer_id', 'status',
        'total_before', 'total_after', 'history',
        'adjusted_by', 'adjusted_at', 'remark', 'created_by',
    ];

    protected $casts = [
        'total_before' => 'decimal:2',
        'total_after' => 'decimal:2',
        'history' => 'array',
        'adjusted_at' => 'datetime',
    ];

    const STATUS_DRAFT = 'draft';

    const STATUS_CONFIRMED = 'confirmed';

    public function items(): HasMany
    {
        return $this->hasMany(PresaleAdjustItem::class, 'presale_adjust_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    /**
     * 追加一条留痕（每次状态变更/编辑调用），history 为 [{action,operator,at,detail}]。
     */
    public function pushHistory(string $action, string $operator, ?string $detail = null): void
    {
        $history = is_array($this->history) ? $this->history : [];
        $history[] = [
            'action' => $action,
            'operator' => $operator,
            'at' => now()->toDateTimeString(),
            'detail' => $detail,
        ];
        $this->history = $history;
    }
}
