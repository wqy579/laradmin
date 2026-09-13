<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ResponseTrait
{
    protected function success($data = null, string $message = 'success', int $code = 200): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ]);
    }

    protected function error(string $message = 'error', int $code = 400, $data = null): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ], $code >= 400 && $code < 600 ? $code : 400);
    }

    protected function created($data = null, string $message = '创建成功'): JsonResponse
    {
        return response()->json([
            'code' => 200,
            'message' => $message,
            'data' => $data,
        ]);
    }

    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    protected function paginated($paginator, string $message = 'success'): JsonResponse
    {
        return response()->json([
            'code' => 200,
            'message' => $message,
            'data' => [
                'list' => $paginator->items(),
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    protected function forbidden(string $message = '无权限访问'): JsonResponse
    {
        return response()->json([
            'code' => 403,
            'message' => $message,
            'data' => null,
        ], 403);
    }

    protected function notFound(string $message = '资源不存在'): JsonResponse
    {
        return response()->json([
            'code' => 404,
            'message' => $message,
            'data' => null,
        ], 404);
    }

    protected function unauthorized(string $message = '未授权'): JsonResponse
    {
        return response()->json([
            'code' => 401,
            'message' => $message,
            'data' => null,
        ], 401);
    }

    protected function validateError(array $errors, string $message = '验证失败'): JsonResponse
    {
        return response()->json([
            'code' => 422,
            'message' => $message,
            'data' => $errors,
        ], 422);
    }
}
