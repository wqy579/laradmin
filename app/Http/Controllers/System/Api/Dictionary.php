<?php

namespace App\Http\Controllers\System\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\System\DictionaryService;

class Dictionary extends Controller
{
    protected $dictionaryService;

    public function __construct(DictionaryService $dictionaryService)
    {
        $this->dictionaryService = $dictionaryService;
    }

    public function index()
    {
        $dictionaries = $this->dictionaryService->getAll();
        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $dictionaries
        ]);
    }

    /**
     * 获取所有字典数据（包含字典项）
     * 用于前端登录后缓存所有字典数据
     */
    public function all()
    {
        $dictionaries = $this->dictionaryService->getAll();

        // 为每个字典添加 items 字段
        $result = array_map(function($dictionary) {
            $items = $this->dictionaryService->getItemsByCode($dictionary['code']);
            $dictionary['items'] = $items;
            return $dictionary;
        }, $dictionaries);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $result
        ]);
    }

    public function getByCode(Request $request)
    {
        $code = $request->input('code');
        $items = $this->dictionaryService->getItemsByCode($code);
        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'code' => $code,
                'items' => $items,
            ]
        ]);
    }

    public function show(int $id)
    {
        $dictionary = $this->dictionaryService->getById($id);
        if (!$dictionary) {
            return response()->json([
                'code' => 404,
                'message' => '字典不存在',
                'data' => null
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $dictionary
        ]);
    }
}
