<?php

namespace Modules\System\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\System\Http\Requests\DictionaryRequest;
use Modules\System\Services\DictionaryService;

class Dictionary extends Controller
{
    protected $dictionaryService;

    public function __construct(DictionaryService $dictionaryService)
    {
        $this->dictionaryService = $dictionaryService;
    }

    public function index(Request $request)
    {
        $result = $this->dictionaryService->getList($request->all());

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result,
        ]);
    }

    public function all()
    {
        $dictionaries = $this->dictionaryService->getAll();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $dictionaries,
        ]);
    }

    public function show(int $id)
    {
        $dictionary = $this->dictionaryService->getById($id);
        if (! $dictionary) {
            return response()->json([
                'code' => 404,
                'message' => '字典不存在',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $dictionary,
        ]);
    }

    public function store(DictionaryRequest $request)
    {
        try {
            $dictionary = $this->dictionaryService->create($request->validated());

            return response()->json([
                'code' => 200,
                'message' => '创建成功',
                'data' => $dictionary,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 422,
                'message' => $e->getMessage(),
                'data' => null,
            ], 422);
        }
    }

    public function update(DictionaryRequest $request, int $id)
    {
        try {
            $dictionary = $this->dictionaryService->update($id, $request->validated());

            return response()->json([
                'code' => 200,
                'message' => '更新成功',
                'data' => $dictionary,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 422,
                'message' => $e->getMessage(),
                'data' => null,
            ], 422);
        }
    }

    public function destroy(int $id)
    {
        try {
            $this->dictionaryService->delete($id);

            return response()->json([
                'code' => 200,
                'message' => '删除成功',
                'data' => null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 400,
                'message' => $e->getMessage(),
                'data' => null,
            ], 400);
        }
    }

    public function batchDelete(DictionaryRequest $request)
    {
        $validated = $request->validated();
        $this->dictionaryService->batchDelete($validated['ids']);

        return response()->json([
            'code' => 200,
            'message' => '批量删除成功',
            'data' => null,
        ]);
    }

    public function batchUpdateStatus(DictionaryRequest $request)
    {
        $validated = $request->validated();
        $this->dictionaryService->batchUpdateStatus(
            $validated['ids'],
            $validated['status']
        );

        return response()->json([
            'code' => 200,
            'message' => '批量更新状态成功',
            'data' => null,
        ]);
    }

    public function showItem(int $id)
    {
        $item = $this->dictionaryService->getItem($id);
        if (! $item) {
            return response()->json([
                'code' => 404,
                'message' => '字典项不存在',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $item,
        ]);
    }

    public function getItemsList(Request $request)
    {
        $result = $this->dictionaryService->getItemsList($request->all());

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result,
        ]);
    }

    public function getAllItems()
    {
        $items = $this->dictionaryService->getAllItems();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $items,
        ]);
    }

    public function storeItem(DictionaryRequest $request)
    {
        try {
            $item = $this->dictionaryService->createItem($request->validated());

            return response()->json([
                'code' => 200,
                'message' => '创建成功',
                'data' => $item,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 422,
                'message' => $e->getMessage(),
                'data' => null,
            ], 422);
        }
    }

    public function updateItem(DictionaryRequest $request, int $id)
    {
        try {
            $item = $this->dictionaryService->updateItem($id, $request->validated());

            return response()->json([
                'code' => 200,
                'message' => '更新成功',
                'data' => $item,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 422,
                'message' => $e->getMessage(),
                'data' => null,
            ], 422);
        }
    }

    public function destroyItem(int $id)
    {
        $this->dictionaryService->deleteItem($id);

        return response()->json([
            'code' => 200,
            'message' => '删除成功',
            'data' => null,
        ]);
    }

    public function batchDeleteItems(DictionaryRequest $request)
    {
        $validated = $request->validated();
        $this->dictionaryService->batchDeleteItems($validated['ids']);

        return response()->json([
            'code' => 200,
            'message' => '批量删除成功',
            'data' => null,
        ]);
    }

    public function batchUpdateItemsStatus(DictionaryRequest $request)
    {
        $validated = $request->validated();
        $this->dictionaryService->batchUpdateItemsStatus(
            $validated['ids'],
            $validated['status']
        );

        return response()->json([
            'code' => 200,
            'message' => '批量更新状态成功',
            'data' => null,
        ]);
    }
}
