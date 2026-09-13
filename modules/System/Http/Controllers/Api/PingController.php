<?php

namespace Modules\System\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PingController extends Controller
{
    public function ping(): JsonResponse
    {
        return response()->json([
            'status' => 0,
            'message' => 'success',
        ]);
    }

    public function importStatus(): JsonResponse
    {
        return response()->json([
            'status' => 0,
            'message' => '数据导入功能已启用,请在服务器上执行: php artisan import:old-system',
            'note' => '导入命令会在每次部署后自动执行',
        ]);
    }

    public function deployNotice(): JsonResponse
    {
        return response()->json([
            'status' => 0,
            'message' => '部署由 GitHub Actions 云端构建并 SSH 部署（见 DEPLOY.md），不通过此接口。',
        ]);
    }
}
