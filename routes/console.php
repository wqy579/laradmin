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

// 请求日志清理：每天凌晨 3:30 删掉 30 天前的 system_log。
// 该表每个请求写一条，曾经因为既不限体积又不清理涨到 2.2GB，
// 引发并发写锁等待、拖慢订单页。体积已由中间件截断，这里兜底防堆积。
Schedule::command('logs:prune --days=30')
    ->dailyAt('03:30')
    ->withoutOverlapping();
