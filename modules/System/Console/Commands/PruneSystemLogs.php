<?php

namespace Modules\System\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 清理 system_log 历史请求日志。
 *
 * 事故背景：LogRequestMiddleware 挂在几乎所有业务路由上，每个请求写一条。
 * 原先既不限体积也不清理，线上 45 天涨到 2.2GB / 3 万行（平均每行 75KB），
 * 表一大，InnoDB 并发写出现锁等待，慢查询里出现被阻塞十几分钟的 SELECT，
 * 表现为「打开新增订单慢、多人同时点会卡死」。
 *
 * 体积问题已由中间件的截断修掉，堆积问题由本命令兜底：
 * 每天删掉超过保留天数的记录，并让 InnoDB 复用空间。
 *
 * 用分批删除而不是一条 DELETE：一次性删几万行会长时间持锁，
 * 大表上反而把线上请求卡住——这正是要避免的场景。
 */
class PruneSystemLogs extends Command
{
    protected $signature = 'logs:prune
                            {--days=30 : 保留最近多少天的日志}
                            {--batch=2000 : 每批删除行数}
                            {--dry-run : 只统计将删除的行数，不实际删除}';

    protected $description = '清理 system_log 中超过保留期的请求日志';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $batch = max(100, (int) $this->option('batch'));
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subDays($days);

        $total = DB::table('system_log')->where('created_at', '<', $cutoff)->count();

        if ($total === 0) {
            $this->info("无需清理：没有早于 {$cutoff->toDateTimeString()} 的日志");

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("[dry-run] 将删除 {$total} 条早于 {$cutoff->toDateTimeString()} 的日志");

            return self::SUCCESS;
        }

        $this->info("开始清理：目标 {$total} 条，早于 {$cutoff->toDateTimeString()}，每批 {$batch} 条");

        $deleted = 0;
        while (true) {
            // 用 id 子查询限定一批，避免 UPDATE/DELETE 扫全表
            $affected = DB::table('system_log')
                ->where('created_at', '<', $cutoff)
                ->limit($batch)
                ->delete();

            if ($affected === 0) {
                break;
            }

            $deleted += $affected;
            $this->line("  已删除 {$deleted}/{$total}");

            // 让出时间片，避免长事务把线上写入堵住
            usleep(100_000);
        }

        $this->info("清理完成，共删除 {$deleted} 条");

        return self::SUCCESS;
    }
}
