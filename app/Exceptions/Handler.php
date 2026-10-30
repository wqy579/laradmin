<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        if ($request->expectsJson() || $request->is('api/*') || $request->is('admin/*')) {
            return $this->handleApiException($request, $e);
        }

        return parent::render($request, $e);
    }

    protected function handleApiException($request, Throwable $e)
    {
        if ($e instanceof ValidationException) {
            return response()->json([
                'code' => 422,
                'message' => $e->getMessage(),
                'data' => $e->errors(),
            ], 422);
        }

        if ($e instanceof AuthenticationException) {
            return response()->json([
                'code' => 401,
                'message' => '未登录或token已过期',
                'data' => null,
            ], 401);
        }

        if ($e instanceof ModelNotFoundException) {
            return response()->json([
                'code' => 404,
                'message' => '资源不存在',
                'data' => null,
            ], 404);
        }

        if ($e instanceof NotFoundHttpException) {
            return response()->json([
                'code' => 404,
                'message' => '请求的资源不存在',
                'data' => null,
            ], 404);
        }

        // QA-14：限流异常单独一条，形状与 RateLimitMiddleware 直出的 429 完全同构
        // （code/message/retry_after + Retry-After 头）。放在通用 HttpException 之前，
        // 否则将来若有路由改走框架的 throttle:，429 会退化成 message 为空、
        // 没有 retry_after 的响应，前端只能显示兜底文案，也拿不到退避时长。
        if ($e instanceof TooManyRequestsHttpException) {
            $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            return response()->json([
                'code' => 429,
                'message' => '操作频繁，请稍后再试',
                'retry_after' => $seconds,
            ], 429, $e->getHeaders());
        }

        if ($e instanceof HttpException) {
            return response()->json([
                'code' => $e->getStatusCode(),
                'message' => $e->getMessage() ?: '请求失败',
                'data' => null,
            ], $e->getStatusCode());
        }

        if (config('app.debug')) {
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ],
            ], 500);
        }

        return response()->json([
            'code' => 500,
            'message' => '服务器内部错误',
            'data' => null,
        ], 500);
    }
}
