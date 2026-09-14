<?php

namespace Modules\System\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log as LaravelLog;
use Modules\System\Services\LogService;
use Throwable;

class LogRequestMiddleware
{
    protected $logService;

    public function __construct(LogService $logService)
    {
        $this->logService = $logService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $startTime = microtime(true);

        $response = $next($request);

        $endTime = microtime(true);
        $executionTime = round(($endTime - $startTime) * 1000, 2); // 转换为毫秒

        // 异步记录日志，不影响响应速度
        $this->logRequest($request, $response, $executionTime);

        return $response;
    }

    /**
     * 记录请求日志
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Http\Response  $response
     * @param  float  $executionTime
     * @return void
     */
    protected function logRequest(Request $request, $response, float $executionTime): void
    {
        try {
            // 获取当前用户信息
            $user = Auth::guard('admin')->user();
            $userId = $user ? $user->id : null;
            $username = $user ? $user->username : 'guest';

            // 解析模块和操作
            $module = $this->parseModule($request->path());
            $action = $this->parseAction($request->method(), $request->path());

            // 获取请求参数（排除敏感信息）
            $params = $this->sanitizeParams($request->all());

            // 获取响应数据
            $result = null;
            if ($response->getStatusCode() >= 400) {
                $result = $response->getContent();
            }

            // 确定日志状态
            $status = $response->getStatusCode() < 400 ? 'success' : 'error';
            $errorMessage = null;
            if ($status === 'error') {
                $errorMessage = $this->extractErrorMessage($result);
            }

            // 构建日志数据
            $logData = [
                'user_id' => $userId,
                'username' => $username,
                'module' => $module,
                'action' => $action,
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'ip' => $this->getClientIp($request),
                'user_agent' => $request->userAgent(),
                'params' => $params,
                'result' => $result,
                'status_code' => $response->getStatusCode(),
                'status' => $status,
                'error_message' => $errorMessage,
                'execution_time' => $executionTime,
            ];

            // 记录到数据库
            $this->logService->create($logData);

            // 同时记录到 Laravel 日志（用于错误）
            if ($status === 'error') {
                LaravelLog::error('API Request Error', [
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'user_id' => $userId,
                    'error' => $errorMessage,
                ]);
            }

        } catch (Throwable $e) {
            // 记录日志失败不影响业务流程
            LaravelLog::error('Log request failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 解析模块名称
     *
     * @param  string  $path
     * @return string
     */
    protected function parseModule(string $path): string
    {
        $segments = explode('/', trim($path, '/'));

        // 移除前缀（如 admin, api）
        $prefixes = ['admin', 'api'];
        if (in_array($segments[0], $prefixes)) {
            array_shift($segments);
        }

        return $segments[0] ?? 'unknown';
    }

    /**
     * 解析操作名称
     *
     * @param  string  $method
     * @param  string  $path
     * @return string
     */
    protected function parseAction(string $method, string $path): string
    {
        $segments = explode('/', trim($path, '/'));

        // 获取资源名称
        $resource = end($segments);

        // 如果资源是 ID，则取前一个作为资源名
        if (is_numeric($resource)) {
            $resource = prev($segments);
        }

        // 根据方法映射操作
        $actionMap = [
            'GET' => '查询',
            'POST' => '创建',
            'PUT' => '更新',
            'PATCH' => '更新',
            'DELETE' => '删除',
        ];

        $action = $actionMap[$method] ?? '操作';

        return $action . ' ' . $resource;
    }

    /**
     * 清理敏感参数
     *
     * @param  array  $params
     * @return array
     */
    protected function sanitizeParams(array $params): array
    {
        $sensitiveKeys = ['password', 'password_confirmation', 'token', 'secret', 'key'];

        array_walk_recursive($params, function (&$value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $value = '******';
            }
        });

        return $params;
    }

    /**
     * 提取错误信息
     *
     * @param  string|null  $content
     * @return string|null
     */
    protected function extractErrorMessage(?string $content): ?string
    {
        if (empty($content)) {
            return null;
        }

        try {
            $data = json_decode($content, true);
            if (isset($data['message'])) {
                return $data['message'];
            }
        } catch (\Exception $e) {
            // JSON 解析失败，返回原始内容
        }

        return $content;
    }

    /**
     * 获取客户端 IP
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function getClientIp(Request $request): string
    {
        $ip = $request->ip();

        // 检查代理头
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'HTTP_X_REAL_IP',
            'HTTP_CF_CONNECTING_IP',
        ];

        foreach ($headers as $header) {
            if ($request->hasHeader($header)) {
                $forwardedIps = explode(',', $request->header($header));
                $ip = trim($forwardedIps[0]);
                break;
            }
        }

        return $ip;
    }
}
