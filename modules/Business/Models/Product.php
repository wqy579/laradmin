<?php

namespace Modules\Business\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $table = 'products';
    protected $appends = ['spec_display', 'conversion_display'];
    protected $fillable = [
        'main_category_id', 'sub_category_id',
        'name', 'spec', 'code', 'image',
        'barcode_large', 'barcode_medium', 'barcode_small',
        'barcode_medium_unit', 'unit_conversion', 'unit_conversion_medium',
        'price_large', 'price_small', 'price_medium',
        'cost_price_large', 'cost_price_small',
        'cost_price',
        'price_unit', 'price_unit_small',
        'shelf_life_days', 'is_online', 'is_active',
        'remark',
    ];
    protected $casts = [
        'is_active' => 'boolean',
        'is_online' => 'boolean',
        'price_large' => 'decimal:2',
        'price_small' => 'decimal:2',
        'price_medium' => 'decimal:2',
        'cost_price' => 'decimal:2',
    ];

    public function mainCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'main_category_id');
    }

    public function subCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'sub_category_id');
    }

    public function stocks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\Modules\Business\Models\Stock::class, 'product_id');
    }

    /**
     * 规格显示文本
     * 根据换算关系生成：1*中数量*小数量 或 1*小数量
     * 例如：1件=4盒=480个 → spec=1*4*120
     */
    public function getSpecDisplayAttribute(): string
    {
        $uc = (float) ($this->unit_conversion ?? 0);
        $ucm = (float) ($this->unit_conversion_medium ?? 0);

        if ($uc <= 0) {
            return '';
        }

        if ($ucm > 0) {
            // 有中单位：1*4*120
            $mediumQty = (int) round($uc / $ucm);
            return "1*{$mediumQty}*{$ucm}";
        }

        // 无中单位：1*480
        return "1*{$uc}";
    }

    /**
     * 整零转换显示文本
     * 例如：1件=4盒=480个
     */
    public function getConversionDisplayAttribute(): string
    {
        $uc = (float) ($this->unit_conversion ?? 0);
        $ucm = (float) ($this->unit_conversion_medium ?? 0);

        if ($uc <= 0) {
            return '';
        }

        $unitLarge = $this->price_unit ?: '件';
        $unitSmall = $this->price_unit_small ?: '个';
        $unitMedium = $this->barcode_medium_unit ?: '盒';

        if ($ucm > 0) {
            // 有中单位：1件=4盒=480个
            $mediumQty = (int) round($uc / $ucm);
            return "1{$unitLarge}={$mediumQty}{$unitMedium}={$uc}{$unitSmall}";
        }

        // 无中单位：1件=480个
        return "1{$unitLarge}={$uc}{$unitSmall}";
    }
}
