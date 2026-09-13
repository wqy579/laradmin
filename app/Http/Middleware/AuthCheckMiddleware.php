<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Auth\Services\PermissionCacheService;
use Symfony\Component\HttpFoundation\Response;

class AuthCheckMiddleware
{
    protected PermissionCacheService $permissionCache;

    public function __construct(PermissionCacheService $permissionCache)
    {
        $this->permissionCache = $permissionCache;
    }

    public function handle(Request $request, Closure $next, ?string $guard = 'api', ?string $permission = null): Response
    {
        if (!auth($guard)->check()) {
            return response()->json([
                'code' => 401,
                'message' => '未登录或token已过期',
                'data' => null,
            ], 401);
        }

        $user = auth($guard)->user();

        if (isset($user->status) && $user->status !== 1) {
            return response()->json([
                'code' => 403,
                'message' => '账号已被禁用',
                'data' => null,
            ], 403);
        }

        if ($permission !== null) {
            if (!$this->checkPermission($user, $permission)) {
                return response()->json([
                    'code' => 403,
                    'message' => '无权限访问',
                    'data' => null,
                ], 403);
            }
        }

        $request->merge(['auth_user' => $user]);

        return $next($request);
    }

    protected function checkPermission($user, string $permission): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if (method_exists($user, 'hasPermission')) {
            return $user->hasPermission($permission);
        }

        return false;
    }

    protected function checkAnyPermission($user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->checkPermission($user, $permission)) {
                return true;
            }
        }
        return false;
    }

    protected function checkAllPermissions($user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->checkPermission($user, $permission)) {
                return false;
            }
        }
        return true;
    }
}
