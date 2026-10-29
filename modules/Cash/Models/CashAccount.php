<?php

namespace Modules\Cash\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 资金账户（CashAccount）
 */
class CashAccount extends Model
{
    protected $table = 'cash_account';

    protected $fillable = [
        'name', 'type', 'account_no', 'opening_balance', 'status', 'sort',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'sort' => 'integer',
    ];

    const TYPE_CASH = 'cash';

    const TYPE_BANK = 'bank';

    const STATUS_ENABLED = 'enabled';

    const STATUS_DISABLED = 'disabled';

    public function flows(): HasMany
    {
        return $this->hasMany(CashFlow::class, 'account_id');
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_CASH => '现金',
            self::TYPE_BANK => '银行',
            default => $type,
        };
    }
}
