<?php

namespace Modules\Archive\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 开账期初库存暂存行（第 2 步）。锁定前的草稿，锁定后才回写 stocks。
 */
class OpeningStock extends Model
{
    protected $table = 'archive_opening_stocks';

    protected $fillable = [
        'wizard_id', 'product_id', 'warehouse_id',
        'quantity', 'cost_price', 'total_amount', 'source',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'cost_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_IMPORT = 'import';

    public function wizard(): BelongsTo
    {
        return $this->belongsTo(OpeningWizard::class, 'wizard_id');
    }
}
