<?php

namespace Modules\Auth\Services;

use Modules\Auth\Models\User;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\Permission;
use Illuminate\Support\Facades\Cache;

class PermissionCacheService
{
    protected $cachePrefix = 'permission:';
    protected $cacheMinutes = 60; // 缓存60分钟

    /**
     * 获取用户的权限列表（带缓存）
     */
    public function getUserPermissions(int $userId): array
    {
        $cacheKey = $this->getUserPermissionsCacheKey($userId);

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheMinutes), function() use ($userId) {
            $user = User::find($userId);
            if (!$user) {
                return [];
            }

            $permissions = [];
            foreach ($user->roles as $role) {
                foreach ($role->permissions as $permission) {
                    $permissions[$permission->id] = [
                        'id' => $permission->id,
                        'title' => $permission->title,
                        'name' => $permission->name,
                        'type' => $permission->type,
                        'path' => $permission->path,
                    ];
                }
            }

            return array_values($permissions);
        });
    }

    /**
     * 获取用户的权限编码列表（带缓存）
     */
    public function getUserPermissionCodes(int $userId): array
    {
        $cacheKey = $this->getUserPermissionCodesCacheKey($userId);

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheMinutes), function() use ($userId) {
            $permissions = $this->getUserPermissions($userId);
            return array_column($permissions, 'name');
        });
    }

    /**
     * 获取用户的菜单树（带缓存）
     */
    public function getUserMenuTree(int $userId): array
    {
        $cacheKey = $this->getUserMenuTreeCacheKey($userId);

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheMinutes), function() use ($userId) {
            $user = User::find($userId);
            if (!$user) {
                return [];
            }

            // 获取用户的所有权限ID
            $permissionIds = [];
            foreach ($user->roles as $role) {
                foreach ($role->permissions as $permission) {
                    $permissionIds[] = $permission->id;
                }
            }

            // 获取菜单类型的权限
            $permissions = Permission::whereIn('id', $permissionIds)
                ->whereIn('type', ['menu', 'api'])
                ->where('status', 1)
                ->orderBy('sort', 'asc')
                ->get();

            return $this->buildMenuTree($permissions->toArray());
        });
    }

    /**
     * 检查用户是否有某个权限（带缓存）
     */
    public function userHasPermission(int $userId, string $permissionCode): bool
    {
        $codes = $this->getUserPermissionCodes($userId);
        return in_array($permissionCode, $codes);
    }

    /**
     * 获取角色的权限列表（带缓存）
     */
    public function getRolePermissions(int $roleId): array
    {
        $cacheKey = $this->getRolePermissionsCacheKey($roleId);

        return Cache::remember($cacheKey, now()->addMinutes($this->cacheMinutes), function() use ($roleId) {
            $role = Role::find($roleId);
            if (!$role) {
                return [];
            }

            return $role->permissions->map(function($permission) {
                return [
                    'id' => $permission->id,
                    'title' => $permission->title,
                    'name' => $permission->name,
                    'type' => $permission->type,
                ];
            })->toArray();
        });
    }

    /**
     * 清除用户权限缓存
     */
    public function clearUserPermissionCache(int $userId): void
    {
        Cache::forget($this->getUserPermissionsCacheKey($userId));
        Cache::forget($this->getUserPermissionCodesCacheKey($userId));
        Cache::forget($this->getUserMenuTreeCacheKey($userId));
    }

    /**
     * 清除角色权限缓存
     */
    public function clearRolePermissionCache(int $roleId): void
    {
        Cache::forget($this->getRolePermissionsCacheKey($roleId));

        // 清除所有拥有该角色的用户权限缓存
        $role = Role::find($roleId);
        if ($role) {
            foreach ($role->users as $user) {
                $this->clearUserPermissionCache($user->id);
            }
        }
    }

    /**
     * 清除所有权限缓存
     */
    public function clearAllPermissionCache(): void
    {
        if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
            $redis = Cache::getStore()->connection();
            $keys = $redis->keys($this->cachePrefix . '*');
            if (!empty($keys)) {
                $redis->del($keys);
            }
        }
    }

    /**
     * 清除指定用户的权限缓存（当用户角色变化时调用）
     */
    public function onUserRolesChanged(int $userId): void
    {
        $this->clearUserPermissionCache($userId);
    }

    /**
     * 清除角色的用户缓存（当角色权限变化时调用）
     */
    public function onRolePermissionsChanged(int $roleId): void
    {
        $this->clearRolePermissionCache($roleId);
    }

    /**
     * 清除权限的所有缓存（当权限本身变化时调用）
     */
    public function onPermissionChanged(int $permissionId): void
    {
        // 清除所有缓存，因为权限变化可能影响所有用户
        $this->clearAllPermissionCache();
    }

    /**
     * 构建菜单树
     */
    protected function buildMenuTree(array $permissions, int $parentId = 0): array
    {
        $tree = [];
        foreach ($permissions as $permission) {
            if ($permission['parent_id'] == $parentId) {
                $node = [
                    'id' => $permission['id'],
                    'title' => $permission['title'],
                    'name' => $permission['name'],
                    'type' => $permission['type'],
                    'path' => $permission['path'],
                    'component' => $permission['component'],
                    'meta' => json_decode($permission['meta'] ?? '{}', true),
                    'sort' => $permission['sort'],
                    'children' => $this->buildMenuTree($permissions, $permission['id']),
                ];

                // 如果没有子节点，移除children字段
                if (empty($node['children'])) {
                    unset($node['children']);
                }

                $tree[] = $node;
            }
        }

        // 按sort排序
        usort($tree, function($a, $b) {
            return $a['sort'] <=> $b['sort'];
        });

        return $tree;
    }

    /**
     * 生成用户权限缓存键
     */
    protected function getUserPermissionsCacheKey(int $userId): string
    {
        return $this->cachePrefix . 'user:' . $userId . ':permissions';
    }

    /**
     * 生成用户权限编码缓存键
     */
    protected function getUserPermissionCodesCacheKey(int $userId): string
    {
        return $this->cachePrefix . 'user:' . $userId . ':permission_codes';
    }

    /**
     * 生成用户菜单树缓存键
     */
    protected function getUserMenuTreeCacheKey(int $userId): string
    {
        return $this->cachePrefix . 'user:' . $userId . ':menu_tree';
    }

    /**
     * 生成角色权限缓存键
     */
    protected function getRolePermissionsCacheKey(int $roleId): string
    {
        return $this->cachePrefix . 'role:' . $roleId . ':permissions';
    }
}
