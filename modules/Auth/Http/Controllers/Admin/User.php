<?php

namespace Modules\Auth\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Auth\Http\Requests\UserRequest;
use Modules\Auth\Services\ImportExportService;
use Modules\Auth\Services\UserOnlineService;
use Modules\Auth\Services\UserService;

class User extends Controller
{
    protected $userService;

    protected $userOnlineService;

    protected $importExportService;

    public function __construct(
        UserService $userService,
        UserOnlineService $userOnlineService,
        ImportExportService $importExportService
    ) {
        $this->userService = $userService;
        $this->userOnlineService = $userOnlineService;
        $this->importExportService = $importExportService;
    }

    /**
     * 获取用户列表
     */
    public function index(Request $request)
    {
        $params = $request->all();
        $result = $this->userService->getList($params);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * 获取用户详情
     */
    public function show(Request $request, $id)
    {
        $result = $this->userService->getById($id);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * 创建用户
     */
    public function store(UserRequest $request)
    {
        $validated = $request->validated();

        $result = $this->userService->create($validated);

        return response()->json([
            'code' => 200,
            'message' => '创建成功',
            'data' => ['id' => $result->id],
        ], 201);
    }

    /**
     * 更新用户
     */
    public function update(UserRequest $request, $id)
    {
        $validated = $request->validated();

        $result = $this->userService->update($id, $validated);

        return response()->json([
            'code' => 200,
            'message' => '更新成功',
            'data' => ['id' => $result->id],
        ]);
    }

    /**
     * 删除用户
     */
    public function destroy(Request $request, $id)
    {
        $this->userService->delete($id);

        return response()->json([
            'code' => 200,
            'message' => '删除成功',
            'data' => null,
        ]);
    }

    /**
     * 批量删除用户
     */
    public function batchDelete(UserRequest $request)
    {
        $validated = $request->validated();

        $count = $this->userService->batchDelete($validated['ids']);

        return response()->json([
            'code' => 200,
            'message' => "成功删除 {$count} 条数据",
            'data' => ['count' => $count],
        ]);
    }

    /**
     * 批量更新用户状态
     */
    public function batchUpdateStatus(UserRequest $request)
    {
        $validated = $request->validated();

        $count = $this->userService->batchUpdateStatus($validated['ids'], $validated['status']);

        return response()->json([
            'code' => 200,
            'message' => "成功更新 {$count} 条数据",
            'data' => ['count' => $count],
        ]);
    }

    /**
     * 批量分配部门
     */
    public function batchAssignDepartment(UserRequest $request)
    {
        $validated = $request->validated();

        $count = $this->userService->batchAssignDepartment($validated['ids'], $validated['department_id']);

        return response()->json([
            'code' => 200,
            'message' => "成功分配 {$count} 条数据",
            'data' => ['count' => $count],
        ]);
    }

    /**
     * 批量分配角色
     */
    public function batchAssignRoles(UserRequest $request)
    {
        $validated = $request->validated();

        $count = $this->userService->batchAssignRoles($validated['ids'], $validated['role_ids'] ?? []);

        return response()->json([
            'code' => 200,
            'message' => "成功分配 {$count} 条数据",
            'data' => ['count' => $count],
        ]);
    }

    /**
     * 导出用户
     */
    public function export(UserRequest $request)
    {
        $validated = $request->validated();

        $result = $this->userService->export($validated);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * 下载导出文件
     */
    public function downloadExport(Request $request, $filename)
    {
        $filePath = $this->importExportService->getExportFilePath($filename);

        if (! file_exists($filePath)) {
            return response()->json([
                'code' => 404,
                'message' => '文件不存在或已过期',
                'data' => null,
            ], 404);
        }

        // 不使用 deleteFileAfterSend：消息中心通知持久存在，用户可能多次下载，
        // 删除文件会导致后续下载拿到 404 响应被保存为空表。改为保留文件，由定时任务清理。
        return response()->download($filePath, $filename);
    }

    /**
     * 导入用户
     */
    public function import(UserRequest $request)
    {
        $validated = $request->validated();

        $file = $request->file('file');
        $realPath = $file->getRealPath();
        $filename = $file->getClientOriginalName();

        $result = $this->importExportService->importUsers($filename, $realPath);

        return response()->json([
            'code' => 200,
            'message' => "导入完成，成功 {$result['success_count']} 条，失败 {$result['error_count']} 条",
            'data' => $result,
        ]);
    }

    /**
     * 下载用户导入模板
     */
    public function downloadTemplate()
    {
        $filename = $this->importExportService->downloadUserTemplate();
        $filePath = $this->importExportService->getExportFilePath($filename);

        return response()->download($filePath, $filename)->deleteFileAfterSend();
    }

    /**
     * 获取在线用户数量
     */
    public function getOnlineCount()
    {
        $count = $this->userOnlineService->getOnlineCount();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => ['count' => $count],
        ]);
    }

    /**
     * 获取在线用户列表
     */
    public function getOnlineUsers(Request $request)
    {
        $limit = $request->get('limit', 100);
        $users = $this->userOnlineService->getOnlineUsers($limit);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => ['list' => $users],
        ]);
    }

    /**
     * 获取用户的所有会话
     */
    public function getUserSessions($userId)
    {
        $sessions = $this->userOnlineService->getUserSessions($userId);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => ['sessions' => $sessions],
        ]);
    }

    /**
     * 强制用户下线（单个会话）
     */
    public function setUserOffline($userId, UserRequest $request)
    {
        $validated = $request->validated();

        if (! empty($validated['token'])) {
            $this->userOnlineService->setOffline($userId, $validated['token']);
        }

        return response()->json([
            'code' => 200,
            'message' => '操作成功',
            'data' => null,
        ]);
    }

    /**
     * 强制用户所有设备下线
     */
    public function setUserAllOffline($userId)
    {
        $this->userOnlineService->setAllOffline($userId);

        return response()->json([
            'code' => 200,
            'message' => '操作成功',
            'data' => null,
        ]);
    }

    /**
     * 重置用户密码
     */
    public function resetPassword($userId)
    {
        $user = User::findOrFail($userId);
        $user->password = bcrypt('123456');
        $user->save();

        return response()->json([
            'code' => 200,
            'message' => '密码重置成功',
            'data' => null,
        ]);
    }
}
