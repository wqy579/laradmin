<?php

namespace Modules\System\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\System\Services\NotificationService;

class RetryUnsentNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:retry-unsent {--limit=100 : Maximum number of notifications to retry}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retry sending unsent notifications via WebSocket';

    /**
     * @var NotificationService
     */
    protected $notificationService;

    /**
     * RetryUnsentNotifications constructor
     */
    public function __construct(NotificationService $notificationService)
    {
        parent::__construct();
        $this->notificationService = $notificationService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $this->info('开始重试未发送的通知...');
        $this->info("最大处理数量: {$limit}");

        try {
            $sentCount = $this->notificationService->retryUnsentNotifications($limit);

            if ($sentCount > 0) {
                $this->info("成功发送 {$sentCount} 条通知");
            } else {
                $this->info('没有需要重试的通知');
            }

            Log::info('重试未发送通知完成', [
                'sent_count' => $sentCount,
                'limit' => $limit,
            ]);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('重试未发送通知失败: '.$e->getMessage());
            Log::error('重试未发送通知失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }
}
