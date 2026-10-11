<?php

namespace Modules\Stock\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Models\Brand;
use Modules\Stock\Models\ProductCategory;

/**
 * 把 brands.category_id 按 code 规则回填。
 *
 * 生产 brands 表的 code 是「BR + 4位分类ID」（如 BR0012 → 分类 ID=12），
 * 但建表迁移没落 category_id 列，2026_10_11_000001 才补上。新环境或历史数据
 * 的 category_id 为空，需要按 code 反解出来回填，品牌↔分类的关联才建立得起来。
 *
 * 幂等：只更新 category_id 为空且 code 能匹配到分类的行；已回填的不动。
 */
class BackfillBrandCategory extends Command
{
    protected $signature = 'brand:backfill-category {--dry-run : 只统计，不写入}';

    protected $description = '按 brands.code(BR+分类ID) 回填 category_id';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $matched = 0;
        $skipped = 0;

        // 只扫 category_id 为空的；已填的不重复处理
        $brands = Brand::whereNull('category_id')->orWhere('category_id', 0)->get();
        foreach ($brands as $brand) {
            if (! preg_match('/^BR0*(\d+)$/', (string) $brand->code, $m)) {
                $skipped++;
                continue;
            }
            $category = ProductCategory::find((int) $m[1]);
            if (! $category) {
                $skipped++;
                continue;
            }
            $matched++;
            if (! $dry) {
                $brand->category_id = $category->id;
                $brand->save();
            }
            $this->line("  {$brand->code} → 分类{$category->id} ({$category->name})");
        }

        $this->info("匹配 {$matched} 条，跳过 {$skipped} 条".($dry ? '（dry-run，未写入）' : '，已写入'));

        return self::SUCCESS;
    }
}
