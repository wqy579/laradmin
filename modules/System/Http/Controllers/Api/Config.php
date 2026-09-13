<?php

namespace Modules\System\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\System\Services\ConfigService;

class Config extends Controller
{
    protected $configService;

    public function __construct(ConfigService $configService)
    {
        $this->configService = $configService;
    }

    public function all()
    {
        $configs = $this->configService->getAllConfig();
        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $configs
        ]);
    }

    public function getByGroup(Request $request)
    {
        $parentId = $request->input('parent_id');
        $group = $request->input('group');

        if (!empty($parentId)) {
            $configs = $this->configService->getByParentId((int) $parentId);
        } else {
            $configs = $this->configService->getByGroup($group);
        }

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $configs
        ]);
    }

    public function getByKey(Request $request)
    {
        $key = $request->input('key');
        $value = $this->configService->getConfigValue($key);
        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'key' => $key,
                'value' => $value,
            ]
        ]);
    }
}
