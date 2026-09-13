<?php

namespace Modules\Auth\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Modules\Auth\Http\Requests\PermissionRequest;
use Modules\Auth\Services\PermissionService;
use Modules\Auth\Services\ImportExportService;
use Illuminate\Http\Request;

class Permission extends Controller
{
    protected $permissionService;
    protected $importExportService;

    public function __construct(
        PermissionService $permissionService,
        ImportExportService $importExportService
    ) {
        $this->permissionService = $permissionService;
        $this->importExportService = $importExportService;
    }

    /**
     * 获取权限列表
     */
    public function index(Request $request)
    {
        $params = $request->all();
        $result = $this->permissionService->getList($params);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * 获取权限树
     */
    public function tree(Request $request)
    {
        $params = $request->all();
        $result = $this->permissionService->getTree($params);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * 获取菜单树
     */
    public function menu(Request $request)
    {
        $result = $this->permissionService->getMenuTree();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * 获取权限详情
     */
    public function show($id)
    {
        $result = $this->permissionService->getById($id);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * 创建权限
     */
    public function store(PermissionRequest $request)
    {
        $validated = $request->validated();

        $result = $this->permissionService->create($validated);

        return response()->json([
            'code' => 200,
            'message' => '创建成功',
            'data' => ['id' => $result->id],
        ], 201);
    }

    /**
     * 更新权限
     */
    public function update(PermissionRequest $request, $id)
    {
        $validated = $request->validated();

        $result = $this->permissionService->update($id, $validated);

        return response()->json([
            'code' => 200,
            'message' => '更新成功',
            'data' => ['id' => $result->id],
        ]);
    }

    /**
     * 删除权限
     */
    public function destroy($id)
    {
        $this->permissionService->delete($id);

        return response()->json([
            'code' => 200,
            'message' => '删除成功',
            'data' => null,
        ]);
    }

    /**
     * 批量删除权限
     */
    public function batchDelete(PermissionRequest $request)
    {
        $validated = $request->validated();

        $count = $this->permissionService->batchDelete($validated['ids']);

        return response()->json([
            'code' => 200,
            'message' => "成功删除 {$count} 条数据",
            'data' => ['count' => $count],
        ]);
    }

    /**
     * 批量更新权限状态
     */
    public function batchUpdateStatus(PermissionRequest $request)
    {
        $validated = $request->validated();

        $count = $this->permissionService->batchUpdateStatus($validated['ids'], $validated['status']);

        return response()->json([
            'code' => 200,
            'message' => "成功更新 {$count} 条数据",
            'data' => ['count' => $count],
        ]);
    }

    /**
     * 导出权限
     */
    public function export(PermissionRequest $request)
    {
        $validated = $request->validated();

        $filename = $this->importExportService->exportPermissions($validated['ids'] ?? []);

        $filePath = $this->importExportService->getExportFilePath($filename);

        return response()->download($filePath, $filename)->deleteFileAfterSend();
    }

    /**
     * 导入权限
     */
    public function import(PermissionRequest $request)
    {
        $validated = $request->validated();

        $file = $request->file('file');
        $realPath = $file->getRealPath();
        $filename = $file->getClientOriginalName();

        $result = $this->importExportService->importPermissions($filename, $realPath);

        return response()->json([
            'code' => 200,
            'message' => "导入完成，成功 {$result['success_count']} 条，失败 {$result['error_count']} 条",
            'data' => $result,
        ]);
    }

    /**
     * 下载权限导入模板
     */
    public function downloadTemplate()
    {
        $filename = $this->importExportService->downloadPermissionTemplate();
        $filePath = $this->importExportService->getExportFilePath($filename);

        return response()->download($filePath, $filename)->deleteFileAfterSend();
    }


    /**
     * 批量更新菜单图标
     */
    public function updateIcons(Request $request)
    {
        $icons = $request->input('icons', []);
        
        foreach ($icons as $name => $icon) {
            $menu = Permission::where('name', $name)->first();
            if ($menu) {
                $menu->meta = ['icon' => $icon];
                $menu->save();
            }
        }
        
        return response()->json([
            'code' => 200,
            'message' => '图标更新成功',
        ]);
    }

}
