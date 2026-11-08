<?php

namespace Modules\Cash\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 存取款转账单（CashDeposit）
 *
 * type：deposit 存款 / withdraw 取款 / transfer 互转。
 * 保存时在 DB::transaction 内同步生成 cash_flow 流水（related_type=cash_deposit），
 * 账户余额由流水累计得出，不在本表冗余。
 */
class CashDeposit extends Model
{
    protected $table = 'cash_deposit';

    protected $fillable = [
        'doc_no', 'biz_date', 'type', 'account_id', 'to_account_id',
        'counterpart', 'amount', 'fee', 'handler', 'department',
        'remark', 'memo', 'status', 'created_by',
    ];

    protected $casts = [
        'biz_date' => 'date',
        'account_id' => 'integer',
        'to_account_id' => 'integer',
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'created_by' => 'integer',
    ];

    const TYPE_DEPOSIT = 'deposit';

    const TYPE_WITHDRAW = 'withdraw';

    const TYPE_TRANSFER = 'transfer';

    const STATUS_CONFIRMED = 'confirmed';

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'account_id');
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_DEPOSIT => '存款',
            self::TYPE_WITHDRAW => '取款',
            self::TYPE_TRANSFER => '互转',
            default => $type,
        };
    }
}
