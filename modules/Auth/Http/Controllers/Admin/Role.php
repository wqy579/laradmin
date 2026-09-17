<?php

namespace Modules\Auth\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Auth\Http\Requests\RoleRequest;
use Modules\Auth\Services\ImportExportService;
use Modules\Auth\Services\RoleService;

class Role extends Controller
{
    protected $roleService;

    protected $importExportService;

    public function __construct(
        RoleService $roleService,
        ImportExportService $importExportService
    ) {
        $this->roleService = $roleService;
        $this->importExportService = $importExportService;
    }

    /**
     * 获取角色列表
     */
    public function index(Request $request)
    {
        $params = $request->all();
        $result = $this->roleService->getList($params);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * 获取所有角色（不分页）
     */
    public function getAll()
    {
        $result = $this->roleService->getAll();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * 获取角色详情
     */
    public function show($id)
    {
        $result = $this->roleService->getById($id);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * 创建角色
     */
    public function store(RoleRequest $request)
    {
        $validated = $request->validated();

        $result = $this->roleService->create($validated);

        return response()->json([
            'code' => 200,
            'message' => '创建成功',
            'data' => ['id' => $result->id],
        ], 201);
    }

    /**
     * 更新角色
     */
    public function update(RoleRequest $request, $id)
    {
        $validated = $request->validated();

        $result = $this->roleService->update($id, $validated);

        return response()->json([
            'code' => 200,
            'message' => '更新成功',
            'data' => ['id' => $result->id],
        ]);
    }

    /**
     * 删除角色
     */
    public function destroy($id)
    {
        $this->roleService->delete($id);

        return response()->json([
            'code' => 200,
            'message' => '删除成功',
            'data' => null,
        ]);
    }

    /**
     * 批量删除角色
     */
    public function batchDelete(RoleRequest $request)
    {
        $validated = $request->validated();

        $count = $this->roleService->batchDelete($validated['ids']);

        return response()->json([
            'code' => 200,
            'message' => "成功删除 {$count} 条数据",
            'data' => ['count' => $count],
        ]);
    }

    /**
     * 批量更新角色状态
     */
    public function batchUpdateStatus(RoleRequest $request)
    {
        $validated = $request->validated();

        $count = $this->roleService->batchUpdateStatus($validated['ids'], $validated['status']);

        return response()->json([
            'code' => 200,
            'message' => "成功更新 {$count} 条数据",
            'data' => ['count' => $count],
        ]);
    }

    /**
     * 分配权限
     */
    public function assignPermissions(RoleRequest $request, $id)
    {
        $validated = $request->validated();

        $this->roleService->assignPermissions($id, $validated['permission_ids']);

        return response()->json([
            'code' => 200,
            'message' => '权限分配成功',
            'data' => null,
        ]);
    }

    /**
     * 获取角色的权限列表
     */
    public function getPermissions($id)
    {
        $result = $this->roleService->getPermissions($id);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => ['tree' => $result],
        ]);
    }

    /**
     * 复制角色
     */
    public function copy(RoleRequest $request, $id)
    {
        $validated = $request->validated();

        $result = $this->roleService->copy($id, $validated);

        return response()->json([
            'code' => 200,
            'message' => '复制成功',
            'data' => ['id' => $result->id],
        ], 201);
    }

    /**
     * 批量复制角色
     */
    public function batchCopy(RoleRequest $request)
    {
        $validated = $request->validated();

        $result = $this->roleService->batchCopy($validated['ids'], $validated);

        return response()->json([
            'code' => 200,
            'message' => "复制完成，成功 {$result['success_count']} 个，失败 {$result['error_count']} 个",
            'data' => $result,
        ]);
    }

    /**
     * 导出角色
     */
    public function export(RoleRequest $request)
    {
        $validated = $request->validated();

        $filename = $this->importExportService->exportRoles($validated['ids'] ?? []);

        $filePath = $this->importExportService->getExportFilePath($filename);

        return response()->download($filePath, $filename)->deleteFileAfterSend();
    }

    /**
     * 导入角色
     */
    public function import(RoleRequest $request)
    {
        $validated = $request->validated();

        $file = $request->file('file');
        $realPath = $file->getRealPath();
        $filename = $file->getClientOriginalName();

        $result = $this->importExportService->importRoles($filename, $realPath);

        return response()->json([
            'code' => 200,
            'message' => "导入完成，成功 {$result['success_count']} 条，失败 {$result['error_count']} 条",
            'data' => $result,
        ]);
    }

    /**
     * 下载角色导入模板
     */
    public function downloadTemplate()
    {
        $filename = $this->importExportService->downloadRoleTemplate();
        $filePath = $this->importExportService->getExportFilePath($filename);

        return response()->download($filePath, $filename)->deleteFileAfterSend();
    }
}
