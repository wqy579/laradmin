<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
    ];

    public function handle(Request $request, Closure $next, ?string $type = null): Response
    {
        $key = $this->resolveRequestSignature($request, $type);

        $limits = $this->getLimits($type);
        $maxAttempts = $limits['max_attempts'];
        $decayMinutes = $limits['decay_minutes'];

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

        RateLimiter::hit($key, $decayMinutes * 60);

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', $maxAttempts - RateLimiter::attempts($key));

        return $response;
    }

    protected function resolveRequestSignature(Request $request, ?string $type): string
    {
        $ip = $request->ip();
        $userId = $request->user()?->id ?? 'guest';
        $route = $request->route()?->getName() ?? $request->path();

        if ($type === 'login' || $type === 'register') {
            $identifier = $request->input('username', $request->input('email', $ip));
            return sha1($type . '|' . $identifier . '|' . $ip);
        }

        return sha1($type . '|' . $userId . '|' . $ip . '|' . $route);
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
        $key = $this->resolveRequestSignature($request, $type);
        RateLimiter::clear($key);
    }
}
