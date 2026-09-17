<?php

namespace Modules\Stock\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiankaiStockCheck extends Model
{
    protected $table = 'liankai_stock_checks';
    public $timestamps = true;

    protected $fillable = [
        'warehouse', 'vehicle_id', 'product_name', 'barcode', 'product_code', 'spec',
        'prod_date', 'yesterday_stock', 'out_qty', 'in_qty', 'adjust_qty',
        'frozen_qty', 'today_stock', 'check_date'
    ];

    protected $casts = [
        'prod_date' => 'date',
        'check_date' => 'date',
    ];

    /**
     * 关联车辆（连凯车仓库 → 本系统车辆）
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /**
     * 获取最新日期的库存核对数据
     */
    public static function latestCheck()
    {
        return static::orderBy('check_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * 按日期分组统计
     */
    public static function getStockByDate($date = null)
    {
        $query = static::query();
        if ($date) {
            $query->whereDate('check_date', $date);
        } else {
            $query->whereDate('check_date', today());
        }
        return $query->selectRaw('warehouse, barcode, product_name, today_stock, yesterday_stock, out_qty, in_qty')
            ->orderBy('warehouse')
            ->orderBy('product_name')
            ->get();
    }
}
