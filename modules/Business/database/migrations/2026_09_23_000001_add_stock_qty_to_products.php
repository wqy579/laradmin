<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 给 products 补 stock_qty 列（建表迁移事后补列、生产未落库）。
 *
 * create_business_tables(2026_08_31) 的 products 定义里有 stock_qty，但生产当年
 * 跑该建表迁移时文件里还没有这列，之后补进建表文件不会重跑，于是生产库一直缺
 * stock_qty —— StockService::syncProductStockQty（下单冻结 / 采购入库后重算）与
 * ProductController::index（with_stock 排序）一碰就 1054 Unknown column。
 * 2026-09-23 销售单弹窗点商品选择器 500 的根因即此。
 *
 * 幂等：hasColumn 判断，已存在（本地/新库）直接跳过。
 * 补列后回填一次存量数据，保证与 stocks 表 quantity 汇总一致。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'stock_qty')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('stock_qty', 10, 3)->default(0)
                    ->after('cost_price_small')->comment('库存数量');
            });
        }

        // 回填存量：每个商品的 stock_qty = 该商品所有仓库 stocks.quantity 之和
        $ids = DB::table('products')->pluck('id')->all();
        foreach ($ids as $id) {
            DB::table('products')->where('id', $id)->update([
                'stock_qty' => (int) DB::table('stocks')
                    ->where('product_id', $id)
                    ->sum('quantity'),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'stock_qty')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('stock_qty');
            });
        }
    }
};
