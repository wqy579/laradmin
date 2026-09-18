<?php

namespace Modules\System\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PingController extends Controller
{
    public function importStatus(): JsonResponse
    {
        // 此前这里声称「数据导入功能已启用」「导入命令会在每次部署后自动执行」，两句话都是假的：
        // import:old-system 命令在本仓库从未实现，deploy.yml 里也没有这一步。
        return response()->json([
            'status' => 0,
            'message' => '旧系统数据导入尚未实装：import:old-system 命令不存在',
            'note' => '如需要该功能，请先在 modules/*/Console/Commands 下实现对应命令',
        ]);
    }
}
