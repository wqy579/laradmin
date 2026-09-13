<?php

namespace Modules\System\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LogRequest;
use Modules\System\Services\LogService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\GenericExport;

class Log extends Controller
{
    protected $logService;

    public function __construct(LogService $logService)
    {
        $this->logService = $logService;
    }

    public function index(LogRequest $request)
    {
        $result = $this->logService->getList($request->validated());
        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result
        ]);
    }

    public function export(LogRequest $request)
    {
        $params = $request->validated();
        $filePath = $this->logService->export($params);

        return response()->download($filePath)->deleteFileAfterSend(true);
    }

    public function show(int $id)
    {
        $log = $this->logService->getById($id);
        if (!$log) {
            return response()->json([
                'code' => 404,
                'message' => '日志不存在',
                'data' => null
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $log
        ]);
    }

    public function destroy(int $id)
    {
        $this->logService->delete($id);
        return response()->json([
            'code' => 200,
            'message' => '删除成功',
            'data' => null
        ]);
    }

    public function batchDelete(\Illuminate\Http\Request $request)
    {
        $this->logService->batchDelete($request->input('ids', []));
        return response()->json([
            'code' => 200,
            'message' => '批量删除成功',
            'data' => null
        ]);
    }

    public function clearLogs(Request $request)
    {
        $days = $request->input('days', 30);
        $this->logService->clearLogs($days);
        return response()->json([
            'code' => 200,
            'message' => '清理成功',
            'data' => null
        ]);
    }

    public function getStatistics(LogRequest $request)
    {
        $statistics = $this->logService->getStatistics($request->validated());
        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $statistics
        ]);
    }
}
