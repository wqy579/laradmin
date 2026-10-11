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

// 库存预警：每天 8:00 查询低于安全库存的商品，汇总通知库管与采购
// （时区见 config/app.php timezone；消息标题带日期前缀保证同日只发一次）
Schedule::command('stock:warning')
    ->dailyAt('08:00')
    ->withoutOverlapping();
