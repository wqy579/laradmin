<?php

namespace Modules\Business\Console\Commands;

use Illuminate\Console\Command;
use Modules\Business\Services\StockSnapshotService;

/**
 * 手动补拍今日库存快照。
 *
 * StockSnapshotMiddleware 挂在所有 /admin 路由上，正常情况首个访问者跨天时自动触发；
 * 快照写失败时中间件会吞掉异常并打上「今日已处理」标记（快照是旁路功能，不能拿它
 * 打挂整个后台），代价是当天缺一条快照——用它补。
 */
class StockSnapshot extends Command
{
    protected $signature = 'stock:snapshot';

    protected $description = '补拍今日库存快照（每日库存快照惰性触发失败后的手动补救）';

    public function handle(StockSnapshotService $service): int
    {
        $count = $service->snapshotToday();

        $this->info("已写入 {$count} 行快照（".now()->toDateString()."）");

        return self::SUCCESS;
    }
}
