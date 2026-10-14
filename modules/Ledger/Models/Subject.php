<?php

namespace Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 会计科目（Subject）
 *
 * 自关联构成科目树，is_leaf=true 的末级科目才能在凭证分录里被选中。
 * 已被凭证使用的科目禁止删除，有发生额/余额的科目禁止改编码与余额方向。
 */
class Subject extends Model
{
    protected $table = 'ledger_subjects';

    protected $fillable = [
        'parent_id', 'code', 'name', 'category', 'balance_direction',
        'is_leaf', 'level', 'aux_types', 'sort', 'status', 'remark',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'is_leaf' => 'boolean',
        'level' => 'integer',
        'sort' => 'integer',
        'aux_types' => 'array',
    ];

    const CATEGORY_ASSET = 'asset';

    const CATEGORY_LIABILITY = 'liability';

    const CATEGORY_COMMON = 'common';

    const CATEGORY_EQUITY = 'equity';

    const CATEGORY_COST = 'cost';

    const CATEGORY_PROFIT_LOSS = 'profit_loss';

    const DIRECTION_DEBIT = 'debit';

    const DIRECTION_CREDIT = 'credit';

    const STATUS_ENABLED = 'enabled';

    const STATUS_DISABLED = 'disabled';

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('code');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(VoucherEntry::class, 'subject_id');
    }

    public function openingBalances(): HasMany
    {
        return $this->hasMany(OpeningBalance::class, 'subject_id');
    }

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            self::CATEGORY_ASSET => '资产类',
            self::CATEGORY_LIABILITY => '负债类',
            self::CATEGORY_COMMON => '共同类',
            self::CATEGORY_EQUITY => '所有者权益类',
            self::CATEGORY_COST => '成本类',
            self::CATEGORY_PROFIT_LOSS => '损益类',
            default => $category,
        };
    }

    public static function directionLabel(string $direction): string
    {
        return $direction === self::DIRECTION_DEBIT ? '借' : '贷';
    }

    /** 损益类科目：用于结转损益到本年利润 */
    public static function profitLossCategories(): array
    {
        return [self::CATEGORY_PROFIT_LOSS, self::CATEGORY_COST];
    }
}
