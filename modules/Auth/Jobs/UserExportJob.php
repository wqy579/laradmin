<?php

namespace Modules\Auth\Jobs;

use App\Contracts\TaskNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Auth\Services\ImportExportService;

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
        return $this->uniqueId.':'.$this->userId;
    }

    public function handle(ImportExportService $importExportService, TaskNotification $notificationService): void
    {
        // 防止重复执行：检查是否已存在相同任务的通知
        // 查询下沉到 System 的 NotificationService，Auth 不再直接读 system_notification 表
        if (! empty($this->taskId) && $notificationService->hasRecent('用户数据导出完成', $this->userId)) {
            Log::info('跳过重复的导出任务', [
                'task_id' => $this->taskId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        $fields = $this->params['fields'] ?? [];
        $filters = $this->params['filters'] ?? [];

        $filename = $importExportService->exportUsersWithFields($fields, $filters);

        $downloadUrl = '/admin/auth/user/download-export/'.$filename;

        $notificationService->create([
            'user_ids' => [$this->userId],
            'title' => '用户数据导出完成',
            'content' => '您的用户数据导出已完成，点击下载查看。',
            'action_data' => [
                [
                    'label' => '下载文件',
                    'type' => TaskNotification::ACTION_DOWNLOAD,
                    'url' => $downloadUrl,
                ],
            ],
            'type' => TaskNotification::TYPE_SUCCESS,
            'category' => TaskNotification::CATEGORY_TASK,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        // 必须用复数 user_ids：实现方的 validateRecipients 只认 user_ids / department_ids，
        // 传单数 user_id 会抛 InvalidArgumentException，把原始异常一起吞掉（此前一直是坏的）
        $notificationService = app(TaskNotification::class);
        $notificationService->create([
            'user_ids' => [$this->userId],
            'title' => '用户数据导出失败',
            'content' => '导出过程中发生错误：'.$exception->getMessage(),
            'type' => TaskNotification::TYPE_ERROR,
            'is_read' => 0,
        ]);
    }
}
