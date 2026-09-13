<?php

namespace App\Services\System;

use App\Models\System\Scheduled;
use App\Models\System\ScheduledLog;
use App\Models\System\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ScheduledService
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function getList(array $params): array
    {
        $query = Scheduled::query();

        if (!empty($params['keyword'])) {
            $query->where(function ($q) use ($params) {
                $q->where('name', 'like', '%' . $params['keyword'] . '%')
                    ->orWhere('command', 'like', '%' . $params['keyword'] . '%');
            });
        }

        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (!empty($params['type'])) {
            $query->where('type', $params['type']);
        }

        $query->orderByDesc('id');

        $pageSize = $params['page_size'] ?? 20;
        $list = $query->paginate($pageSize);

        return [
            'list' => $list->items(),
            'total' => $list->total(),
            'page' => $list->currentPage(),
            'page_size' => $list->perPage(),
        ];
    }

    public function getAll(): array
    {
        return Scheduled::orderByDesc('id')->get()->toArray();
    }

    public function getById(int $id): ?Scheduled
    {
        return Scheduled::find($id);
    }

    public function create(array $data): Scheduled
    {
        $this->validate($data);

        $data['status'] = Scheduled::STATUS_IDLE;

        if (!empty($data['expression'])) {
            $data['next_run_at'] = $this->calculateNextRunAt($data['expression'], $data['timezone'] ?? 'Asia/Shanghai');
        } elseif (!empty($data['interval'])) {
            $data['next_run_at'] = now()->addSeconds($data['interval'])->format('Y-m-d H:i:s');
        }

        return Scheduled::create($data);
    }

    public function update(int $id, array $data): Scheduled
    {
        $task = Scheduled::findOrFail($id);

        if ($task->status === Scheduled::STATUS_RUNNING) {
            throw new \Exception('运行中的任务不允许修改，请先暂停或停止');
        }

        $this->validate($data, $id);

        if (!empty($data['expression'])) {
            $data['next_run_at'] = $this->calculateNextRunAt(
                $data['expression'],
                $data['timezone'] ?? $task->timezone
            );
        } elseif (!empty($data['interval'])) {
            $base = $task->last_run_at ?? now();
            $data['next_run_at'] = $base->addSeconds($data['interval'])->format('Y-m-d H:i:s');
        }

        $task->update($data);
        return $task->fresh();
    }

    public function delete(int $id): bool
    {
        $task = Scheduled::findOrFail($id);

        if ($task->status === Scheduled::STATUS_RUNNING) {
            throw new \Exception('运行中的任务不允许删除，请先停止');
        }

        return $task->delete();
    }

    public function batchDelete(array $ids): bool
    {
        $running = Scheduled::whereIn('id', $ids)->where('status', Scheduled::STATUS_RUNNING)->exists();
        if ($running) {
            throw new \Exception('选中的任务中包含运行中的任务，请先停止');
        }

        Scheduled::whereIn('id', $ids)->delete();
        return true;
    }

    /**
     * 启动任务（idle/stopped/error -> idle，使其可被调度器拾取）
     */
    public function start(int $id): Scheduled
    {
        $task = Scheduled::findOrFail($id);

        if ($task->status === Scheduled::STATUS_IDLE) {
            return $task; // 已是空闲状态
        }

        $task->status = Scheduled::STATUS_IDLE;
        $task->next_run_at = $task->calculateNextRunAt();
        $task->save();

        return $task->fresh();
    }

    /**
     * 暂停任务（running -> paused）
     */
    public function pause(int $id): Scheduled
    {
        $task = Scheduled::findOrFail($id);

        if (!$task->canTransitionTo(Scheduled::STATUS_PAUSED)) {
            throw new \Exception('当前状态不允许暂停');
        }

        $task->status = Scheduled::STATUS_PAUSED;
        $task->save();

        return $task->fresh();
    }

    /**
     * 恢复任务（paused -> idle）
     */
    public function resume(int $id): Scheduled
    {
        $task = Scheduled::findOrFail($id);

        if (!$task->canTransitionTo(Scheduled::STATUS_RUNNING)) {
            throw new \Exception('当前状态不允许恢复');
        }

        $task->status = Scheduled::STATUS_IDLE;
        $task->next_run_at = $task->calculateNextRunAt();
        $task->save();

        return $task->fresh();
    }

    /**
     * 停止任务（任意状态 -> stopped）
     */
    public function stop(int $id): Scheduled
    {
        $task = Scheduled::findOrFail($id);

        if (!$task->canTransitionTo(Scheduled::STATUS_STOPPED)) {
            throw new \Exception('当前状态不允许停止');
        }

        $task->status = Scheduled::STATUS_STOPPED;
        $task->save();

        return $task->fresh();
    }

    /**
     * 立即执行任务（绕过 cron 检查）
     */
    public function runNow(int $id): array
    {
        $task = Scheduled::findOrFail($id);

        if ($task->status === Scheduled::STATUS_RUNNING) {
            throw new \Exception('任务正在运行中，请稍后再试');
        }

        return $this->execute($task);
    }

    /**
     * 执行单个任务
     */
    public function execute(Scheduled $task): array
    {
        $startTime = microtime(true);
        $log = ScheduledLog::create([
            'scheduled_id' => $task->id,
            'status' => ScheduledLog::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $task->update([
            'status' => Scheduled::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $output = '';
        $errorMessage = '';
        $logStatus = ScheduledLog::STATUS_SUCCESS;

        try {
            switch ($task->type) {
                case Scheduled::TYPE_ARTISAN:
                    $output = $this->executeArtisan($task);
                    break;

                case Scheduled::TYPE_JOB:
                    $this->executeJob($task);
                    $output = '任务已分发到队列';
                    break;

                case Scheduled::TYPE_SHELL:
                    $output = $this->executeShell($task);
                    break;

                default:
                    throw new \Exception("不支持的任务类型: {$task->type}");
            }

            $task->increment('run_count');
            $task->status = Scheduled::STATUS_IDLE;
        } catch (\Exception $e) {
            $logStatus = ScheduledLog::STATUS_FAILED;
            $errorMessage = $e->getMessage();
            $task->status = Scheduled::STATUS_ERROR;
            $task->increment('error_count');
        }

        $executionTime = (int) round((microtime(true) - $startTime) * 1000);
        $nextRunAt = $task->calculateNextRunAt();

        $task->update([
            'last_run_at' => now(),
            'next_run_at' => $nextRunAt,
            'started_at' => null,
        ]);

        $log->update([
            'status' => $logStatus,
            'output' => Str::limit($output, 65535),
            'error_message' => Str::limit($errorMessage, 65535),
            'execution_time' => $executionTime,
            'finished_at' => now(),
        ]);

        // 发送通知
        $this->notifyIfNeeded($task, $logStatus, $errorMessage, $executionTime);

        return [
            'status' => $logStatus,
            'output' => $output,
            'error_message' => $errorMessage,
            'execution_time' => $executionTime,
        ];
    }

    /**
     * 处理超时任务
     */
    public function handleTimeout(Scheduled $task): void
    {
        // 更新最近的运行中日志为超时
        $log = $task->logs()
            ->where('status', ScheduledLog::STATUS_RUNNING)
            ->latest()
            ->first();

        if ($log) {
            $log->update([
                'status' => ScheduledLog::STATUS_TIMEOUT,
                'error_message' => "任务执行超时（超过 {$task->timeout} 秒）",
                'finished_at' => now(),
                'execution_time' => $task->started_at ? now()->diffInMilliseconds($task->started_at) : null,
            ]);
        }

        $task->update([
            'status' => Scheduled::STATUS_ERROR,
            'started_at' => null,
        ]);
        $task->increment('error_count');

        $this->notifyIfNeeded($task, ScheduledLog::STATUS_TIMEOUT, '任务执行超时', 0);
    }

    /**
     * 获取执行日志
     */
    public function getExecutionLogs(int $taskId, array $params): array
    {
        $query = ScheduledLog::where('scheduled_id', $taskId);

        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        $query->orderByDesc('id');

        $pageSize = $params['page_size'] ?? 20;
        $list = $query->paginate($pageSize);

        return [
            'list' => $list->items(),
            'total' => $list->total(),
            'page' => $list->currentPage(),
            'page_size' => $list->perPage(),
        ];
    }

    /**
     * 清空任务执行日志
     */
    public function clearLogs(int $taskId): bool
    {
        ScheduledLog::where('scheduled_id', $taskId)->delete();
        return true;
    }

    /**
     * 获取统计信息
     */
    public function getStatistics(): array
    {
        return [
            'total' => Scheduled::count(),
            'idle' => Scheduled::where('status', Scheduled::STATUS_IDLE)->count(),
            'running' => Scheduled::where('status', Scheduled::STATUS_RUNNING)->count(),
            'paused' => Scheduled::where('status', Scheduled::STATUS_PAUSED)->count(),
            'stopped' => Scheduled::where('status', Scheduled::STATUS_STOPPED)->count(),
            'error' => Scheduled::where('status', Scheduled::STATUS_ERROR)->count(),
        ];
    }

    /**
     * 执行 Artisan 命令
     */
    protected function executeArtisan(Scheduled $task): string
    {
        $exitCode = Artisan::call($task->command, $task->parameters ?? []);
        $output = Artisan::output();

        if ($exitCode !== 0) {
            throw new \Exception("Artisan 命令执行失败，退出码: {$exitCode}");
        }

        return $output;
    }

    /**
     * 分发队列任务
     */
    protected function executeJob(Scheduled $task): void
    {
        $jobClass = $task->command;

        if (!class_exists($jobClass)) {
            throw new \Exception("Job 类不存在: {$jobClass}");
        }

        $params = $task->parameters ?? [];
        if (!empty($params)) {
            dispatch(new $jobClass(...$params));
        } else {
            dispatch(new $jobClass());
        }
    }

    /**
     * 执行 Shell 命令
     */
    protected function executeShell(Scheduled $task): string
    {
        $command = $task->command;
        $output = '';
        $resultCode = 0;

        exec($command . ' 2>&1', $output, $resultCode);
        $output = implode("\n", $output);

        if ($resultCode !== 0) {
            throw new \Exception("Shell 命令执行失败，退出码: {$resultCode}\n{$output}");
        }

        return $output;
    }

    /**
     * 验证数据
     */
    protected function validate(array $data, ?int $excludeId = null): void
    {
        // expression 和 interval 至少填一个
        if (empty($data['expression']) && empty($data['interval'])) {
            throw new \Exception('Cron表达式和执行间隔至少填写一项');
        }

        // 验证 Cron 表达式格式
        if (!empty($data['expression'])) {
            try {
                new \Cron\CronExpression($data['expression']);
            } catch (\Exception $e) {
                throw new \Exception('无效的 Cron 表达式: ' . $data['expression']);
            }
        }
    }

    /**
     * 计算下次运行时间
     */
    protected function calculateNextRunAt(string $expression, string $timezone = 'Asia/Shanghai'): ?string
    {
        try {
            $cron = new \Cron\CronExpression($expression);
            return $cron->getNextRunDate('now', 0, false, $timezone)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 按需发送通知
     */
    protected function notifyIfNeeded(Scheduled $task, string $logStatus, string $errorMessage, int $executionTime): void
    {
        try {
            $adminUserIds = $this->getAdminUserIds();
            if (empty($adminUserIds)) {
                return;
            }

            if ($logStatus === ScheduledLog::STATUS_FAILED || $logStatus === ScheduledLog::STATUS_TIMEOUT) {
                $this->notificationService->sendToUsers(
                    $adminUserIds,
                    '调度任务执行失败: ' . $task->name,
                    sprintf("任务 %s 执行%s，%s耗时：%d 毫秒", $task->name, $logStatus === ScheduledLog::STATUS_TIMEOUT ? '超时' : '失败', $errorMessage ? "错误：{$errorMessage}\n" : '', $executionTime),
                    Notification::TYPE_ERROR,
                    Notification::CATEGORY_TASK,
                    ['scheduled_id' => $task->id, 'scheduled_name' => $task->name, 'error_message' => $errorMessage, 'execution_time' => $executionTime]
                );
            }
        } catch (\Exception $e) {
            Log::error('发送调度任务通知失败', ['scheduled_id' => $task->id, 'error' => $e->getMessage()]);
        }
    }

    protected function getAdminUserIds(): array
    {
        try {
            return \App\Models\Auth\User::where('status', 1)
                ->whereHas('roles', fn($q) => $q->where('name', 'admin'))
                ->pluck('id')
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }
}
