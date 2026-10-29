<?php

namespace Modules\Mall\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Stock\Models\Product;

/**
 * 商城商品上架记录。
 *
 * 单一数据源：价格/规格/单位换算读 products 表，本表只存上架开关与商城展示字段。
 */
class MallProduct extends Model
{
    protected $table = 'mall_products';

    protected $fillable = [
        'product_id', 'is_on_shelf', 'sort', 'slogan', 'remark', 'created_by',
    ];

    protected $casts = [
        'is_on_shelf' => 'boolean',
        'sort' => 'integer',
        'created_by' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** 状态标签类型（Design System 语义色） */
    public static function shelfTagType(bool $on): string
    {
        return $on ? 'success' : 'info';
    }
}
