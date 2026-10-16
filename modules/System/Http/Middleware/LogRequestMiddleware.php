<?php

namespace Modules\System\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log as LaravelLog;
use Modules\System\Services\LogService;
use Throwable;

class LogRequestMiddleware
{
    /**
     * 单条日志各字段的体积上限（字符数）。
     *
     * 事故背景：这套中间件挂在几乎所有业务路由上，原先 params 原样落库，
     * 线上跑了一个半月后 system_log 涨到 2.2GB / 3 万行，平均每行约 75KB
     * （连 GET 列表查询的 params 都有 81KB）。表一大，InnoDB 里并发写大行
     * 开始互相锁，慢查询日志里出现被阻塞 11~28 分钟的 SELECT，表现为
     * 「打开新增订单很慢、多人同时点会卡死」。这里给每个自由文本字段设上限，
     * 让单行日志回到 KB 以内。
     *
     * params 用 JSON 表示，留 4KB：足够看清查询条件/提交的关键字段；
     * 超限时降级成「键名清单 + 总量说明」，保留可排查性又不撑爆行。
     */
    private const MAX_PARAMS_LEN = 4096;

    private const MAX_RESULT_LEN = 4096;

    private const MAX_URL_LEN = 2048;

    private const MAX_UA_LEN = 512;

    /** 单个字符串值超过这个长度就先截断，避免一个巨型字段把整个 JSON 顶爆 */
    private const MAX_VALUE_LEN = 1024;

    /**
     * 从 params 里整键剔除的字段。
     *
     * 事故复盘（2026-10-16，与上面 2.2GB 膨胀是同一个现场）：
     * 光加长度上限没能解决问题——`auth_user` 是 auth.check 中间件
     * `$request->merge(['auth_user' => $user])` 挂上来的完整用户模型，
     * 经 $request->all() 一起被取出。实测一条销售订单列表查询的 params
     * 共 79,959 字节，其中 auth_user 独占 79,850 字节（99.9%）——
     * 用户模型几十个字段里还嵌套着角色/权限，逐字段截断到 1KB 根本压不住。
     *
     * 而且它压根不该出现在请求参数日志里：那是鉴权上下文，不是调用方传的参数。
     * 需要审计「谁调的」有 username / user_id 两个独立列，信息不丢。
     */
    private const EXCLUDED_PARAM_KEYS = ['auth_user'];

    protected $logService;

    public function __construct(LogService $logService)
    {
        $this->logService = $logService;
    }

    /**
     * Handle an incoming request.
     *
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
     * @param  Response  $response
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

            // 获取请求参数（排除敏感信息），并压缩到安全体积内
            $params = $this->shrinkParams($this->sanitizeParams($request->all()));

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
                'url' => mb_substr($request->fullUrl(), 0, self::MAX_URL_LEN),
                'ip' => $this->getClientIp($request),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, self::MAX_UA_LEN),
                'params' => $params,
                'result' => $result === null ? null : mb_substr($result, 0, self::MAX_RESULT_LEN),
                'status_code' => $response->getStatusCode(),
                'status' => $status,
                'error_message' => $errorMessage === null ? null : mb_substr($errorMessage, 0, self::MAX_RESULT_LEN),
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

        return $action.' '.$resource;
    }

    /**
     * 清理敏感参数
     */
    protected function sanitizeParams(array $params): array
    {
        // 先整键剔除鉴权上下文对象。必须在 array_walk_recursive 之前做：
        // auth_user 是个嵌套几十层的模型数组，递归走一遍再逐字段截断，
        // 既浪费 CPU（每条日志都要遍历整个用户模型），又压不到 KB 以内。
        foreach (self::EXCLUDED_PARAM_KEYS as $excluded) {
            unset($params[$excluded]);
        }

        $sensitiveKeys = ['password', 'password_confirmation', 'token', 'secret', 'key'];

        array_walk_recursive($params, function (&$value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $value = '******';

                return;
            }
            // 单个值也限长：一个 base64 图片或超长文本就能把整行 JSON 撑到几十 KB
            if (is_string($value) && mb_strlen($value) > self::MAX_VALUE_LEN) {
                $value = mb_substr($value, 0, self::MAX_VALUE_LEN).'…[截断]';
            }
        });

        return $params;
    }

    /**
     * 把 params 压到 MAX_PARAMS_LEN 以内。
     *
     * 正常请求直接原样返回；超限时不再硬塞，而是换成「每个键的值长度摘要」——
     * 既能看出请求带了哪些字段、哪个字段异常大，又不会把日志行撑到几十 KB。
     */
    protected function shrinkParams(array $params): array
    {
        $json = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || mb_strlen($json) <= self::MAX_PARAMS_LEN) {
            return $params;
        }

        $summary = ['_truncated' => true, '_original_bytes' => mb_strlen($json)];

        foreach ($params as $k => $v) {
            if (is_array($v)) {
                $summary[$k] = '[array '.count($v).']';
            } elseif (is_string($v)) {
                $summary[$k] = mb_strlen($v) > 64
                    ? mb_substr($v, 0, 64).'…[len='.mb_strlen($v).']'
                    : $v;
            } else {
                $summary[$k] = $v;
            }
        }

        return $summary;
    }

    /**
     * 提取错误信息
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
