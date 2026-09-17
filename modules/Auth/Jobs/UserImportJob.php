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
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Auth\Imports\UserImport;

class UserImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function handle(TaskNotification $notificationService): void
    {
        $import = new UserImport;
        Excel::import($import, $this->path, 'local');

        $successCount = $import->getSuccessCount();
        $errors = method_exists($import, 'getErrors') ? $import->getErrors() : [];

        Log::info('用户导入完成', ['path' => $this->path, 'success' => $successCount, 'errors' => count($errors)]);

        $content = "导入处理完成：成功 {$successCount} 条";
        if (! empty($errors)) {
            $content .= '，失败 '.count($errors).' 条';
        }

        $notificationService->create([
            'user_ids' => [Auth::id() ?: 1],
            'title' => '用户数据导入完成',
            'content' => $content,
            'type' => empty($errors) ? TaskNotification::TYPE_SUCCESS : TaskNotification::TYPE_WARNING,
            'category' => TaskNotification::CATEGORY_TASK,
        ]);

        Storage::disk('local')->delete($this->path);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('用户导入失败', ['path' => $this->path, 'error' => $exception->getMessage()]);

        app(TaskNotification::class)->create([
            'user_ids' => [Auth::id() ?: 1],
            'title' => '用户数据导入失败',
            'content' => '导入过程中发生错误：'.$exception->getMessage(),
            'type' => TaskNotification::TYPE_ERROR,
            'is_read' => 0,
        ]);
    }
}
