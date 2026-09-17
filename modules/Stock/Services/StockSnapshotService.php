<?php

namespace Modules\Stock\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 每日库存快照（惰性触发，跟随代码走，换环境零配置）
 *
 * 机制：与旧系统一致——首个访问者发现日期跨天时，自动把 stocks 当前库存
 * 写为当天快照。同一天重复触发只更新不新增（幂等）。
 */
class StockSnapshotService
{
    private const CACHE_KEY = 'stock_snapshot_last_date';

    private const LOCK_KEY = 'stock_snapshot_lock';

    /**
     * 检查并生成快照（供中间件每次请求调用，开销极小）
     *
     * 快照是旁路功能，但它的中间件挂在所有 /admin/* 上，所以这里吞掉一切异常：
     * 快照写失败最多丢一天的快照（可用 php artisan stock:snapshot 补拍），
     * 不能拿它去打挂整个后台。
     */
    public function checkAndSnapshot(): bool
    {
        $today = now()->toDateString();

        // 缓存命中：今天已处理过，直接跳过
        if (Cache::get(self::CACHE_KEY) === $today) {
            return false;
        }

        // 并发锁：同一秒多个首次请求只放行一个执行快照
        $lock = Cache::lock(self::LOCK_KEY, 10);
        if (! $lock->get()) {
            return false;
        }

        try {
            // 锁内二次检查
            if (Cache::get(self::CACHE_KEY) === $today) {
                return false;
            }

            $lastDate = DB::table('stock_snapshots')->max('snapshot_date');
            if ($lastDate === null || $lastDate < $today) {
                $this->snapshotToday();
            }

            // 标记今天已处理
            Cache::put(self::CACHE_KEY, $today, now()->addDays(2));

            return true;
        } catch (\Throwable $e) {
            Log::error('每日库存快照失败', ['error' => $e->getMessage()]);

            // 失败也打上今日标记：否则每个管理端请求都重打一次这条失败日志。
            // 代价是当天不会有快照，补拍走 `php artisan stock:snapshot`。
            Cache::put(self::CACHE_KEY, $today, now()->addDays(2));

            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * 生成今天快照（幂等：同日重复执行只更新）
     *
     * 用 query builder 的 updateOrInsert 逐行 upsert，而不用
     * `INSERT ... SELECT ... ON DUPLICATE KEY UPDATE`——后者是 MySQL 专有语法，
     * 在 SQLite（本地 :memory: 测试库）上必然抛 QueryException。快照中间件挂在
     * 所有 /admin/* 路由上，一次抛错就是整个后台 500。
     * stocks 表规模是「商品 × 仓库」，逐行 upsert 的成本可以接受。
     *
     * @return int 写入（新增或更新）的快照行数
     */
    public function snapshotToday(): int
    {
        $today = now()->toDateString();
        $now = now();
        $count = 0;

        DB::transaction(function () use ($today, $now, &$count) {
            $stocks = DB::table('stocks')
                ->select('product_id', 'warehouse_id', 'quantity', 'frozen_qty')
                ->get();

            foreach ($stocks as $stock) {
                DB::table('stock_snapshots')->updateOrInsert(
                    [
                        'snapshot_date' => $today,
                        'product_id' => $stock->product_id,
                        'warehouse_id' => $stock->warehouse_id,
                    ],
                    [
                        'quantity' => $stock->quantity,
                        'frozen_qty' => $stock->frozen_qty,
                        'created_at' => $now,
                    ]
                );
                $count++;
            }
        });

        return $count;
    }
}
