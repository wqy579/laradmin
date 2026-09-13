<?php

namespace App\Jobs;

use App\Services\Auth\ImportExportService;
use App\Services\System\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

class UserExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $params;
    protected int $userId;
    protected string $taskId;

    public $uniqueId = 'user-export';
    public $uniqueFor = 60; // 60秒内相同 uniqueId 的任务只执行一次

    public function __construct(array $params, int $userId = 0)
    {
        $this->params = $params;
        $this->userId = $userId;
        $this->taskId = $params['task_id'] ?? '';
    }

    public function uniqueId(): string
    {
        return $this->uniqueId . ':' . $this->userId;
    }

    public function handle(ImportExportService $importExportService, NotificationService $notificationService): void
    {
        // 防止重复执行：检查是否已存在相同任务ID的通知
        if (!empty($this->taskId)) {
            $existingNotification = \App\Models\System\Notification::where('title', '用户数据导出完成')
                ->whereJsonContains('user_ids', $this->userId)
                ->where('created_at', '>=', now()->subMinutes(5))
                ->first();
            if ($existingNotification) {
                \Illuminate\Support\Facades\Log::info('跳过重复的导出任务', [
                    'task_id' => $this->taskId,
                    'user_id' => $this->userId,
                    'existing_notification_id' => $existingNotification->id,
                ]);
                return;
            }
        }

        $fields = $this->params['fields'] ?? [];
        $filters = $this->params['filters'] ?? [];

        $filename = $importExportService->exportUsersWithFields($fields, $filters);

        $downloadUrl = '/admin/auth/user/download-export/' . $filename;

        $notificationService->create([
            'user_ids' => [$this->userId],
            'title' => '用户数据导出完成',
            'content' => "您的用户数据导出已完成，点击下载查看。",
            'action_data' => [
                [
                    'label' => '下载文件',
                    'type' => 'download',
                    'url' => $downloadUrl,
                ]
            ],
            'type' => 'success',
            'category' => 'task',
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $notificationService = app(NotificationService::class);
        $notificationService->create([
            'user_id' => $this->userId,
            'title' => '用户数据导出失败',
            'content' => '导出过程中发生错误：' . $exception->getMessage(),
            'type' => 'error',
            'is_read' => 0,
        ]);
    }
}
