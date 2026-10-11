<?php

namespace Modules\Stock\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 品牌主数据。
 *
 * 连凯的「品牌」是商品分类的一级维度（一级节点=品牌，二级=品类）。本系统
 * 把品牌抽成独立主数据表，便于独立启停、排序与审计；brand_id 挂在 products 上，
 * 报表按品牌汇总走这条外键（见 DashboardController::brandProportion）。
 */
class Brand extends Model
{
    use SoftDeletes;

    protected $table = 'brands';

    protected $fillable = [
        'code', 'name', 'category_id', 'sort',
        'is_active', 'remark', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /** 关联一级分类（分类本身仍由 ProductController::categories 管理） */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'brand_id');
    }

    /**
     * 商品数量访问器。
     *
     * 只在需要单条计数时用；列表页请走 BrandController::index 的一次
     * GROUP BY，避免每行一条 COUNT（与 ProductController::categories 同一原则）。
     */
    public function productsCount(): int
    {
        return $this->products()->count();
    }
}
