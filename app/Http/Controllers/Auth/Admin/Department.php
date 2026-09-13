<?php

namespace App\Http\Controllers\Auth\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DepartmentRequest;
use App\Services\Auth\DepartmentService;
use App\Services\Auth\ImportExportService;
use Illuminate\Http\Request;

class Department extends Controller
{
    protected $departmentService;
    protected $importExportService;

    public function __construct(
        DepartmentService $departmentService,
        ImportExportService $importExportService
    ) {
        $this->departmentService = $departmentService;
        $this->importExportService = $importExportService;
    }

    /**
     * 获取部门列表
     */
    public function index(Request $request)
    {
        $params = $request->all();
        $result = $this->departmentService->getList($params);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * 获取部门树
     */
    public function tree(Request $request)
    {
        $params = $request->only(['keyword', 'status']);
        $result = $this->departmentService->getTree($params);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * 获取所有部门（不分页）
     */
    public function getAll()
    {
        $result = $this->departmentService->getAll();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => ['list' => $result],
        ]);
    }

    /**
     * 获取部门详情
     */
    public function show($id)
    {
        $result = $this->departmentService->getById($id);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * 创建部门
     */
    public function store(DepartmentRequest $request)
    {
        $validated = $request->validated();

        $result = $this->departmentService->create($validated);

        return response()->json([
            'code' => 200,
            'message' => '创建成功',
            'data' => ['id' => $result->id],
        ], 201);
    }

    /**
     * 更新部门
     */
    public function update(DepartmentRequest $request, $id)
    {
        $validated = $request->validated();

        $result = $this->departmentService->update($id, $validated);

        return response()->json([
            'code' => 200,
            'message' => '更新成功',
            'data' => ['id' => $result->id],
        ]);
    }

    /**
     * 删除部门
     */
    public function destroy($id)
    {
        $this->departmentService->delete($id);

        return response()->json([
            'code' => 200,
            'message' => '删除成功',
            'data' => null,
        ]);
    }

    /**
     * 批量删除部门
     */
    public function batchDelete(DepartmentRequest $request)
    {
        $validated = $request->validated();

        $count = $this->departmentService->batchDelete($validated['ids']);

        return response()->json([
            'code' => 200,
            'message' => "成功删除 {$count} 条数据",
            'data' => ['count' => $count],
        ]);
    }

    /**
     * 批量更新部门状态
     */
    public function batchUpdateStatus(DepartmentRequest $request)
    {
        $validated = $request->validated();

        $count = $this->departmentService->batchUpdateStatus($validated['ids'], $validated['status']);

        return response()->json([
            'code' => 200,
            'message' => "成功更新 {$count} 条数据",
            'data' => ['count' => $count],
        ]);
    }

    /**
     * 导出部门
     */
    public function export(DepartmentRequest $request)
    {
        $validated = $request->validated();

        $filename = $this->importExportService->exportDepartments($validated['ids'] ?? []);

        $filePath = $this->importExportService->getExportFilePath($filename);

        return response()->download($filePath, $filename)->deleteFileAfterSend();
    }

    /**
     * 导入部门
     */
    public function import(DepartmentRequest $request)
    {
        $validated = $request->validated();

        $file = $request->file('file');
        $realPath = $file->getRealPath();
        $filename = $file->getClientOriginalName();

        $result = $this->importExportService->importDepartments($filename, $realPath);

        return response()->json([
            'code' => 200,
            'message' => "导入完成，成功 {$result['success_count']} 条，失败 {$result['error_count']} 条",
            'data' => $result,
        ]);
    }

    /**
     * 下载部门导入模板
     */
    public function downloadTemplate()
    {
        $filename = $this->importExportService->downloadDepartmentTemplate();
        $filePath = $this->importExportService->getExportFilePath($filename);

        return response()->download($filePath, $filename)->deleteFileAfterSend();
    }
}
