<?php

namespace Modules\Cash\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 刷卡收款记录（CashCardTxn）
 *
 * 卡号脱敏与「仅成功且当日可退」是本页的两条硬规则：脱敏在 cardNoMasked()
 * 统一收口（含导出），可退判定在 refundable()，接口与列表共用同一判断，
 * 避免前后端两套规则打架。
 */
class CashCardTxn extends Model
{
    protected $table = 'cash_card_txn';

    protected $fillable = [
        'serial_no', 'txn_time', 'customer_id', 'customer_name',
        'salesman_id', 'salesman_name', 'card_type', 'card_no', 'card_last4',
        'amount', 'fee', 'status', 'rel_no', 'refunded_by', 'refunded_at',
    ];

    protected $casts = [
        'txn_time' => 'datetime',
        'customer_id' => 'integer',
        'salesman_id' => 'integer',
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'refunded_by' => 'integer',
        'refunded_at' => 'datetime',
    ];

    const STATUS_SUCCESS = 'success';

    const STATUS_FAIL = 'fail';

    const STATUS_REFUND = 'refund';

    /**
     * 卡号脱敏：保留前 4 位与后 4 位，中间一律 8 个星号。
     * 长度不足 12 的卡号按「前 4 + 剩余全遮蔽」处理，绝不透出中段。
     */
    public static function maskCardNo(?string $cardNo): string
    {
        $s = (string) $cardNo;
        $len = strlen($s);

        if ($len <= 4) {
            return $s === '' ? '' : str_repeat('*', $len);
        }
        if ($len <= 12) {
            return substr($s, 0, 4).str_repeat('*', $len - 4);
        }

        return substr($s, 0, 4).str_repeat('*', 8).substr($s, -4);
    }

    /** 仅成功且当日可退 */
    public function refundable(): bool
    {
        return $this->status === self::STATUS_SUCCESS
            && $this->txn_time !== null
            && $this->txn_time->toDateString() === now()->toDateString();
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_SUCCESS => '成功',
            self::STATUS_FAIL => '失败',
            self::STATUS_REFUND => '已退款',
            default => $status,
        };
    }

    public static function cardTypeLabel(string $type): string
    {
        return $type === 'credit' ? '贷记' : '借记';
    }
}
