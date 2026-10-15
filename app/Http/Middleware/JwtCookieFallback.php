<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JWT 的 Cookie 兜底通道。
 *
 * 背景：部分反向代理（含本项目的公网发布通道）会剥离或覆盖请求里的
 * Authorization 头，导致浏览器发出的 Bearer token 到不了后端，
 * 所有需要鉴权的接口一律 401「未登录或token已过期」。
 * Cookie 是标准请求头、不会被这类代理改写，且浏览器会自动携带，
 * 因此这里把 token 同时写入 Cookie，并在 Authorization 不可用/不可信时
 * 用 Cookie 里的 token 还原出 Authorization 头。
 *
 * 这样前端不用改一行代码：登录照旧返回 token，只是后端额外种一个 Cookie。
 */
class JwtCookieFallback
{
    public const COOKIE_NAME = 'access_token';

    public function handle(Request $request, Closure $next): Response
    {
        // —— 请求侧：用 Cookie 还原 Authorization ——
        $cookieToken = $request->cookie(self::COOKIE_NAME);
        if ($cookieToken) {
            // Cookie 里的 token 是我们自己签发并校验过的，优先于请求头：
            // 代理可能塞进一个自己的（非 JWT）Authorization 值，那种值不该被信任。
            $request->headers->set('Authorization', 'Bearer ' . $cookieToken);
        }

        $response = $next($request);

        // —— 响应侧：登录成功后种 Cookie；登出时清除 ——
        if ($request->is('admin/auth/login')) {
            $token = $this->extractToken($response);
            if ($token) {
                $response->headers->setCookie(
                    cookie(self::COOKIE_NAME, $token, 1440, '/', null, false, true, false, 'Lax')
                );
            }
        } elseif ($request->is('admin/auth/logout')) {
            $response->headers->clearCookie(self::COOKIE_NAME, '/');
        }

        return $response;
    }

    /**
     * 从登录响应体里取出 token（只认 { data: { token } } 结构，取不到就跳过）。
     */
    private function extractToken(Response $response): ?string
    {
        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $payload = json_decode($response->getContent(), true);
        if (!is_array($payload)) {
            return null;
        }

        $token = $payload['data']['token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }
}
