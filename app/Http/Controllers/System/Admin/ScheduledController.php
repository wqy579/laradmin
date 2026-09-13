<?php

namespace App\Http\Controllers\System\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\ScheduledRequest;
use App\Services\System\ScheduledService;
use Illuminate\Http\Request;

class ScheduledController extends Controller
{
    protected ScheduledService $scheduledService;

    public function __construct(ScheduledService $scheduledService)
    {
        $this->scheduledService = $scheduledService;
    }

    public function index(Request $request)
    {
        $result = $this->scheduledService->getList($request->all());
        return $this->success($result);
    }

    public function all()
    {
        $data = $this->scheduledService->getAll();
        return $this->success($data);
    }

    public function show(int $id)
    {
        $task = $this->scheduledService->getById($id);
        if (!$task) {
            return $this->notFound('调度任务不存在');
        }
        return $this->success($task);
    }

    public function store(ScheduledRequest $request)
    {
        $task = $this->scheduledService->create($request->validated());
        return $this->success($task, '创建成功');
    }

    public function update(ScheduledRequest $request, int $id)
    {
        $task = $this->scheduledService->update($id, $request->validated());
        return $this->success($task, '更新成功');
    }

    public function destroy(int $id)
    {
        $this->scheduledService->delete($id);
        return $this->success(null, '删除成功');
    }

    public function batchDelete(ScheduledRequest $request)
    {
        $validated = $request->validated();
        $this->scheduledService->batchDelete($validated['ids']);
        return $this->success(null, '批量删除成功');
    }

    public function start(int $id)
    {
        $task = $this->scheduledService->start($id);
        return $this->success($task, '启动成功');
    }

    public function pause(int $id)
    {
        $task = $this->scheduledService->pause($id);
        return $this->success($task, '暂停成功');
    }

    public function resume(int $id)
    {
        $task = $this->scheduledService->resume($id);
        return $this->success($task, '恢复成功');
    }

    public function stop(int $id)
    {
        $task = $this->scheduledService->stop($id);
        return $this->success($task, '禁用成功');
    }

    public function runNow(int $id)
    {
        $result = $this->scheduledService->runNow($id);
        return $this->success($result, '执行完成');
    }

    public function executionLogs(Request $request, int $id)
    {
        $result = $this->scheduledService->getExecutionLogs($id, $request->all());
        return $this->success($result);
    }

    public function clearLogs(int $id)
    {
        $this->scheduledService->clearLogs($id);
        return $this->success(null, '日志已清空');
    }

    public function statistics()
    {
        $data = $this->scheduledService->getStatistics();
        return $this->success($data);
    }
}
