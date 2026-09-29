<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Stock\Models\Warehouse;

class ReturnOrder extends Model
{
    protected $table = 'returns';

    protected $fillable = ['order_no', 'customer_id', 'warehouse_id', 'return_date', 'total_amount', 'total_qty', 'status', 'created_by', 'approved_by', 'approved_at', 'remark'];

    protected $casts = ['return_date' => 'date'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        // 必须显式给出外键：Eloquent 按属主类名推断出 return_order_id，
        // 而 return_items 表里那列叫 return_id。漏了这里，退货单的
        // store/update/approve/show 全部 SQL 报错 500（明细插入即失败）。
        return $this->hasMany(ReturnItem::class, 'return_id');
    }
}
