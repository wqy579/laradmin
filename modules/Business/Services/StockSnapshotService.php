<?php

namespace Modules\Business\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        if (!$lock->get()) {
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
        } finally {
            $lock->release();
        }
    }

    /**
     * 生成今天快照（幂等：同日重复执行只更新）
     */
    public function snapshotToday(): int
    {
        return DB::statement(
            "INSERT INTO stock_snapshots (snapshot_date, product_id, warehouse_id, quantity, frozen_qty, created_at)
             SELECT CURDATE(), product_id, warehouse_id, quantity, frozen_qty, NOW()
             FROM stocks
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), frozen_qty = VALUES(frozen_qty), created_at = NOW()"
        );
    }
}
