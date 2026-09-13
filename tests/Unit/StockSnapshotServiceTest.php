<?php

namespace Tests\Unit;

use App\Services\Business\StockSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/**
 * T5（Unit）：库存快照服务
 *
 * StockSnapshotMiddleware 挂在所有 /admin/* 路由上，每次请求调用
 * StockSnapshotService::checkAndSnapshot()。本类验证：
 *  1) 当日已处理过时中间件开销为零（缓存短路）；
 *  2) 快照写入 SQL 的可移植性现状（当前为 MySQL 专用语法）。
 *
 * 注意：tests/TestCase.php 里预热了 stock_snapshot_last_date 缓存键，
 * 因此 Feature 用例不会踩到 2) 的 SQL；只有本类直接操作该缓存键。
 */
class StockSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_and_snapshot_skips_when_today_already_processed(): void
    {
        Cache::put('stock_snapshot_last_date', now()->toDateString(), now()->addDays(2));

        $service = new StockSnapshotService();

        $this->assertFalse($service->checkAndSnapshot(), '当日已处理过应直接短路返回 false，不触碰数据库');
        $this->assertSame(0, (int) \DB::table('stock_snapshots')->count());
    }

    public function test_snapshot_write_sql_is_mysql_specific_on_sqlite(): void
    {
        // 已知缺陷（本用例钉住现状）：snapshotToday() 使用 CURDATE()/NOW()/ON DUPLICATE KEY UPDATE，
        // 这是 MySQL 专用语法，在 SQLite 上必然抛 QueryException。
        // 若本用例失败（不再抛异常），说明快照 SQL 已改为可移植写法 —— 请把该用例改写为正向断言
        // （执行后 stock_snapshots 出现当日快照行），并同步移除 tests/TestCase.php 中的缓存预热补丁。
        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();
        \DB::table('stocks')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 7,
            'frozen_qty' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new StockSnapshotService();

        $this->expectException(QueryException::class);
        $service->snapshotToday();
    }

    public function test_first_check_of_day_raises_on_sqlite(): void
    {
        // 已知缺陷（本用例钉住现状）：缓存没有当日标记时（例如每天第一个请求、或缓存被清），
        // checkAndSnapshot() 会走到快照写入并在 SQLite 上抛异常。
        // 由于中间件不捕获异常，这意味着在非 MySQL 环境（测试/本地 CI）下所有 /admin/* 请求都会 500。
        Cache::forget('stock_snapshot_last_date');
        Cache::forget('stock_snapshot_lock');

        $service = new StockSnapshotService();

        $this->expectException(QueryException::class);
        $service->checkAndSnapshot();
    }
}
