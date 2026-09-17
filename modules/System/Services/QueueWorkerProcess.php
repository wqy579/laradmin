<?php

namespace Modules\System\Services;

use Hhxsv5\LaravelS\Swoole\Process\CustomProcessInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Swoole\Http\Server;
use Swoole\Process;

class QueueWorkerProcess implements CustomProcessInterface
{
    public static function callback(Server $swoole, Process $process)
    {
        $processName = 'LaravelS:queue-worker';
        $process->name($processName);

        while (true) {
            try {
                Artisan::call('queue:work', [
                    '--once' => true,
                    '--tries' => 3,
                    '--timeout' => 60,
                    '--sleep' => 3,
                    '--queue' => 'default',
                ]);

                sleep(1);
            } catch (\Exception $e) {
                Log::error('队列工作进程异常', [
                    'error' => $e->getMessage(),
                ]);
                sleep(5);
            }
        }
    }

    public static function onReload(Server $swoole, Process $process)
    {
        Log::info('队列工作进程重载');
    }
}
