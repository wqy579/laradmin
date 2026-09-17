<?php

namespace Tests\Stock\Unit;

use Modules\Stock\Services\StockSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * T5（Unit）：库存快照服务
 *
 * StockSnapshotMiddleware 挂在所有 /admin/* 路由上，每次请求调用
 * StockSnapshotService::checkAndSnapshot()。本类验证：
 *  1) 当日已处理过时中间件开销为零（缓存短路）；
 *  2) 快照写入可移植、幂等（MySQL 与 SQLite 都能跑，同日重复执行只更新不新增）；
 *  3) 快照写失败不向外抛异常——它是旁路功能，不能把整个后台打挂。
 *
 * 快照 SQL 此前是 MySQL 专有语法（CURDATE()/NOW()/ON DUPLICATE KEY UPDATE），
 * 在 SQLite 上必然抛 QueryException，测试只能在 tests/TestCase.php 里预热缓存键
 * 把中间件短路绕过。现在已改为可移植写法，本类直接验证真实写入。
 */
class StockSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    /** 往 stocks 表插一行库存，返回商品/仓库 id，供三个用例共用 */
    private function seedStock(int $quantity = 7, int $frozen = 1): array
    {
        $product = $this->makeProduct();
        $warehouse = $this->makeWarehouse();

        \DB::table('stocks')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
            'frozen_qty' => $frozen,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$product->id, $warehouse->id];
    }

    private function clearSnapshotCache(): void
    {
        Cache::forget('stock_snapshot_last_date');
        Cache::forget('stock_snapshot_lock');
    }

    public function test_check_and_snapshot_skips_when_today_already_processed(): void
    {
        Cache::put('stock_snapshot_last_date', now()->toDateString(), now()->addDays(2));

        $service = new StockSnapshotService();

        $this->assertFalse($service->checkAndSnapshot(), '当日已处理过应直接短路返回 false，不触碰数据库');
        $this->assertSame(0, (int) \DB::table('stock_snapshots')->count());
    }

    public function test_first_check_of_day_writes_today_snapshot(): void
    {
        $this->clearSnapshotCache();
        [$productId, $warehouseId] = $this->seedStock();

        $service = new StockSnapshotService();

        $this->assertTrue($service->checkAndSnapshot(), '缓存无今日标记时应生成快照');

        $row = \DB::table('stock_snapshots')
            ->where('snapshot_date', now()->toDateString())
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        $this->assertNotNull($row, 'stock_snapshots 应出现当日快照行');
        $this->assertSame(7.0, (float) $row->quantity, '快照可用库存应与 stocks 一致');
        $this->assertSame(1.0, (float) $row->frozen_qty, '快照冻结库存应与 stocks 一致');
        $this->assertNotNull($row->created_at);
    }

    public function test_snapshot_is_idempotent_for_same_day(): void
    {
        [$productId, $warehouseId] = $this->seedStock();

        $service = new StockSnapshotService();

        // 同日重复执行只更新，不新增行
        $this->assertSame(1, $service->snapshotToday(), '首拍应写入 1 行');
        $this->assertSame(1, $service->snapshotToday(), '同日重拍应返回同样行数');
        $this->assertSame(1, (int) \DB::table('stock_snapshots')->count(), '同日重复执行不得新增行');

        // 库存变化后快照跟随最新值
        \DB::table('stocks')->where('product_id', $productId)->update(['quantity' => 3]);
        $service->snapshotToday();

        $this->assertSame(
            3.0,
            (float) \DB::table('stock_snapshots')
                ->where('snapshot_date', now()->toDateString())
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->value('quantity')
        );
        $this->assertSame(1, (int) \DB::table('stock_snapshots')->count());
    }

    public function test_snapshot_today_is_a_noop_when_there_is_no_stock(): void
    {
        $this->assertSame(0, (new StockSnapshotService())->snapshotToday());
        $this->assertSame(0, (int) \DB::table('stock_snapshots')->count());
    }

    public function test_check_and_snapshot_swallows_write_failure_instead_of_breaking_requests(): void
    {
        // 快照中间件挂在所有 /admin/* 路由上。快照写失败时这里必须吞掉异常，
        // 否则一次快照失败就是整个后台 500（这正是原先 MySQL 专用 SQL 造成的后果）。
        $this->clearSnapshotCache();

        $service = Mockery::mock(StockSnapshotService::class)->makePartial();
        $service->shouldReceive('snapshotToday')->once()->andThrow(new \RuntimeException('snapshot failed'));

        $this->assertFalse($service->checkAndSnapshot(), '写入失败应返回 false 而不是抛出异常');

        // 失败后仍打上今日标记：否则每个管理端请求都重打一次这条失败日志
        $this->assertSame(now()->toDateString(), Cache::get('stock_snapshot_last_date'));
    }
}
