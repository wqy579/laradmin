<?php

namespace App\Http\Controllers\System\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\ConfigRequest;
use Illuminate\Http\Request;
use App\Services\System\ConfigService;

class Config extends Controller
{
    protected $configService;

    public function __construct(ConfigService $configService)
    {
        $this->configService = $configService;
    }

    public function index(Request $request)
    {
        $result = $this->configService->getList($request->all());
        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result
        ]);
    }

    public function show(int $id)
    {
        $config = $this->configService->getById($id);
        if (!$config) {
            return response()->json([
                'code' => 404,
                'message' => '配置不存在',
                'data' => null
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $config
        ]);
    }

    public function store(ConfigRequest $request)
    {
        try {
            $config = $this->configService->create($request->validated());
            return response()->json([
                'code' => 200,
                'message' => '创建成功',
                'data' => $config
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 422,
                'message' => $e->getMessage(),
                'data' => null
            ], 422);
        }
    }

    public function update(ConfigRequest $request, int $id)
    {
        try {
            $config = $this->configService->update($id, $request->validated());
            return response()->json([
                'code' => 200,
                'message' => '更新成功',
                'data' => $config
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 422,
                'message' => $e->getMessage(),
                'data' => null
            ], 422);
        }
    }

    public function destroy(int $id)
    {
        try {
            $this->configService->delete($id);
            return response()->json([
                'code' => 200,
                'message' => '删除成功',
                'data' => null
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 400,
                'message' => $e->getMessage(),
                'data' => null
            ], 400);
        }
    }

    public function batchDelete(ConfigRequest $request)
    {
        $validated = $request->validated();
        $this->configService->batchDelete($validated['ids']);
        return response()->json([
            'code' => 200,
            'message' => '批量删除成功',
            'data' => null
        ]);
    }

    public function batchUpdateStatus(ConfigRequest $request)
    {
        $validated = $request->validated();
        $this->configService->batchUpdateStatus(
            $validated['ids'],
            $validated['status']
        );
        return response()->json([
            'code' => 200,
            'message' => '批量更新状态成功',
            'data' => null
        ]);
    }

    public function batchSave(Request $request)
    {
        $items = $request->input('items', []);
        $this->configService->batchSave($items);
        return response()->json([
            'code' => 200,
            'message' => '保存成功',
            'data' => null
        ]);
    }

    public function tree()
    {
        $tree = $this->configService->getTree();
        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $tree
        ]);
    }

    public function all(Request $request)
    {
        $params = $request->only(['item_type']);
        $configs = $this->configService->getAllConfigs($params);
        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $configs
        ]);
    }

    public function getGroups()
    {
        $groups = $this->configService->getGroups();
        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $groups
        ]);
    }

    public function getByGroup(Request $request)
    {
        $parentId = $request->input('parent_id');
        $group = $request->input('group');

        if (!empty($parentId)) {
            $configs = $this->configService->getByParentId((int) $parentId);
        } elseif (!empty($group)) {
            $configs = $this->configService->getByGroup($group);
        } else {
            $configs = $this->configService->getAllConfigs($request->only('item_type'));
        }

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $configs
        ]);
    }
}
