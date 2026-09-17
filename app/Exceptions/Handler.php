<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
