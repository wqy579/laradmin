<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitMiddleware
{
    protected $defaultMaxAttempts = 60;

    protected $defaultDecayMinutes = 1;

    protected $routeLimits = [
        'login' => ['max_attempts' => 5, 'decay_minutes' => 15],
        'register' => ['max_attempts' => 3, 'decay_minutes' => 60],
        'password.reset' => ['max_attempts' => 3, 'decay_minutes' => 60],
        'password.email' => ['max_attempts' => 3, 'decay_minutes' => 60],
        'verification.send' => ['max_attempts' => 5, 'decay_minutes' => 30],
        // 文件上传：单次 10MB，可打满磁盘。见 resolveSignatures() 里 upload 分支的双桶说明
        'upload' => ['max_attempts' => 30, 'decay_minutes' => 15],
    ];

    public function handle(Request $request, Closure $next, ?string $type = null): Response
    {
        // 一个 type 可能对应多个独立计数桶（见 resolveSignatures）：
        // 任一桶超限即拒绝，全部桶都要 hit，否则攻击者可以靠只打满其中一桶绕过。
        $signatures = $this->resolveSignatures($request, $type);

        $limits = $this->getLimits($type);
        $maxAttempts = $limits['max_attempts'];
        $decayMinutes = $limits['decay_minutes'];

        foreach ($signatures as $key) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                $seconds = RateLimiter::availableIn($key);
                $minutes = ceil($seconds / 60);

                return response()->json([
                    'code' => 429,
                    'message' => "请求过于频繁，请在 {$minutes} 分钟后重试",
                    'data' => [
                        'retry_after' => $seconds,
                    ],
                ], 429);
            }
        }

        foreach ($signatures as $key) {
            RateLimiter::hit($key, $decayMinutes * 60);
        }

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set(
            'X-RateLimit-Remaining',
            min(array_map(fn ($key) => $maxAttempts - RateLimiter::attempts($key), $signatures))
        );

        return $response;
    }

    /**
     * 解析出这个请求要占用的所有限流计数键。
     *
     * 返回多个键是刻意的：单一维度总有绕过路径（IP 可伪造、账号可被盗），
     * 多个独立桶同时计数，攻击者要同时打满每一个才行。
     */
    protected function resolveSignatures(Request $request, ?string $type): array
    {
        $ip = $this->clientIp($request);
        $userId = $this->resolveUserId($request);
        $route = $request->route()?->getName() ?? $request->path();

        // 登录/注册：暴破防护只按账号计，IP 不进键。
        // 旧实现是 sha1(login|username|ip)，IP 一可伪造这条限流就等于交给
        // 攻击者自己定桶——每换一个 X-Real-IP 就拿到全新 5 次配额，
        // 而真正的暴力破解（轮换账号）本来就不受这个桶限制。
        // 只按账号计语义也更对：上千个用户共用一个 NAT 出口时，
        // 各账号依然各自独立，不会互相牵连误伤。
        if ($type === 'login' || $type === 'register') {
            $identifier = $request->input('username', $request->input('email', 'anonymous'));

            return [sha1($type.'|'.$identifier)];
        }

        if ($type === 'upload') {
            // 两个独立桶：
            //   IP 桶——出口维度兜底，防匿名流量打满磁盘；
            //   账号桶——持有效 token 的账号即使轮换伪造 IP 也逃不掉。
            // 只留 IP 维度的话，账号桶等于没有（IP 可伪造）。
            return [sha1($type.'|'.$ip), sha1($type.'|user|'.$userId)];
        }

        return [sha1($type.'|'.$userId.'|'.$ip.'|'.$route)];
    }

    /**
     * 用于限流与审计的客户端 IP。
     *
     * ⚠️ 在 laravel-s 下这个值不可信：vendor/hhxsv5/laravel-s/src/Swoole/Request.php 的
     * toIlluminateRequest() 会把请求头 x-real-ip 无条件写进 REMOTE_ADDR，
     * 而 laravel-s 没有把 SwooleRequest 存进容器，原始 TCP 对端地址到 Laravel 层
     * 已经取不回来了。
     *
     * 所以这条链成立的前提在部署侧：8000 端口只对反向代理开放（服务器上把
     * LARAVELS_LISTEN_IP 设为 127.0.0.1），X-Real-IP 只能由 nginx 这一跳写。
     * 一旦进程被公网直连，所有按 IP 的限流都能靠轮换这个头绕过——
     * 这正是 login/register 的计数键里不再带 IP 的原因。
     */
    protected function clientIp(Request $request): string
    {
        // $request->ip() 在拿不到对端地址时返回 null（CLI、未传 REMOTE_ADDR 的代理、
        // 或 toIlluminateRequest 没拼上 x-real-ip 的边界场景），而本方法签名是 : string，
        // 直接 return 会触发 TypeError 让整条限流链路 500——登录/注册首当其冲。
        // 给一个稳定占位：无法识别来源的请求共享同一桶，语义上等价于「未知出口」。
        return $request->ip() ?? '0.0.0.0';
    }

    /**
     * 当前请求的用户 id。
     *
     * auth.check 通过 $request->merge(['auth_user' => $user]) 把已鉴权用户挂到入参上，
     * rate.limit 排在其后，这里直接取；不重新解析 guard 是因为各路由用的 guard 名不同
     * （admin / api），而本中间件拿不到。
     */
    protected function resolveUserId(Request $request): string
    {
        $user = $request->input('auth_user');

        if ($user instanceof Authenticatable) {
            return (string) $user->getAuthIdentifier();
        }

        return (string) ($request->user()?->getAuthIdentifier() ?? 'guest');
    }

    protected function getLimits(?string $type): array
    {
        if ($type && isset($this->routeLimits[$type])) {
            return $this->routeLimits[$type];
        }

        return [
            'max_attempts' => $this->defaultMaxAttempts,
            'decay_minutes' => $this->defaultDecayMinutes,
        ];
    }

    public function clearRateLimit(Request $request, ?string $type = null): void
    {
        foreach ($this->resolveSignatures($request, $type) as $key) {
            RateLimiter::clear($key);
        }
    }
}
