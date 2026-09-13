<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 调度任务已由 laravel-s ScheduledTimerJob 驱动（秒级精度），此处保留仅供无 Swoole 环境回退使用
// Schedule::command('scheduled:run')->everyMinute()->withoutOverlapping();

// 消息队列工作进程（仅非 Swoole 环境使用，Swoole 环境由 laravels.php processes 配置）
Schedule::command('queue:work --redis --tries=3 --timeout=60 --sleep=3')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// 重试通过 WebSocket 未成功发送的通知
Schedule::command('notifications:retry-unsent --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping();
