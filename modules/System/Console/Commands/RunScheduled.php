<?php

namespace Modules\System\Console\Commands;

use Modules\System\Models\Scheduled;
use Modules\System\Services\ScheduledService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunScheduled extends Command
{
    protected $signature = 'scheduled:run {--force : 强制运行所有活跃任务}';

    protected $description = '运行到期的调度任务';

    public function handle(ScheduledService $service): int
    {
        $tasks = Scheduled::whereIn('status', Scheduled::$activeStatuses)->get();

        if ($tasks->isEmpty()) {
            $this->info('没有需要执行的调度任务。');
            return self::SUCCESS;
        }

        $executed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($tasks as $task) {
            // 检查是否到期
            if (!$this->option('force') && !$task->isDue()) {
                $skipped++;
                continue;
            }

            // 检查防重叠锁
            if ($task->without_overlapping && $task->status === Scheduled::STATUS_RUNNING) {
                if (!$task->isOverdue()) {
                    $skipped++;
                    continue;
                }
                // 超时了，标记为超时
                $service->handleTimeout($task);
            }

            try {
                $service->execute($task);
                $executed++;
                $this->info("✓ [{$task->name}] 执行完成");
            } catch (\Exception $e) {
                $failed++;
                $this->error("✗ [{$task->name}] 执行失败: {$e->getMessage()}");
                Log::error('调度任务执行失败', [
                    'scheduled_id' => $task->id,
                    'name' => $task->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("执行: {$executed} | 跳过: {$skipped} | 失败: {$failed}");
        return self::SUCCESS;
    }
}
