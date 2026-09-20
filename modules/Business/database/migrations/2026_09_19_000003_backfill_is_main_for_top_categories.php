<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | 回填 product_categories.is_main：add_category_levels(2026_09_03) 给该列加
 | default(false) 且无回填，该迁移首次跑时所有现存顶级分类被刷成非主分类，
 | ProductController@categories 查 where is_main=true 返回空 → 销售单弹窗
 | 三栏选择器显示「无分类」。按数据约定（parent_id IS NULL 即一级主分类）回填。
 |
 | 幂等：只更新 is_main=false 的顶级分类，已正确的记录不动。
 | 不可逆：down 无法区分本次回填与原本就 is_main=true 的记录，留空。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('product_categories')
            ->whereNull('parent_id')
            ->where('is_main', false)
            ->update(['is_main' => true]);
    }

    public function down(): void
    {
        // 不可逆，留空。
    }
};
