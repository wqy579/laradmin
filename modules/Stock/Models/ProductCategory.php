<?php

namespace Modules\Stock\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductCategory extends Model
{
    protected $table = 'product_categories';

    protected $fillable = ['name', 'parent_id', 'is_main', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'is_main' => 'boolean'];

    public function parent(): HasOne
    {
        return $this->hasOne(ProductCategory::class, 'id', 'parent_id');
    }

    public function subCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class, 'parent_id')->orderBy('sort_order');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'sub_category_id');
    }

    public function mainProducts(): HasMany
    {
        return $this->hasMany(Product::class, 'main_category_id');
    }
}
