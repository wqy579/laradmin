<?php

namespace App\Services\System;

use Hhxsv5\LaravelS\Swoole\Timer\CronJob;
use Illuminate\Support\Facades\Artisan;

class ScheduledTimerJob extends CronJob
{
    public function interval()
    {
        return 1000; // 每秒执行一次
    }

    public function isImmediate()
    {
        return false;
    }

    public function run()
    {
        Artisan::call('scheduled:run');
    }
}
