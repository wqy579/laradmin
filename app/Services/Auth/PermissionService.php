<?php

namespace App\Services\Auth;

use App\Models\Auth\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PermissionService
{
    /**
     * 获取权限列表
     */
    public function getList(array $params): array
    {
        $query = Permission::query();

        // 搜索条件
        if (!empty($params['keyword'])) {
            $query->where(function ($q) use ($params) {
                $q->where('title', 'like', '%' . $params['keyword'] . '%')
                    ->orWhere('name', 'like', '%' . $params['keyword'] . '%');
            });
        }

        if (!empty($params['type'])) {
            $query->where('type', $params['type']);
        }

        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }

        // 排序
        $orderBy = $params['order_by'] ?? 'sort';
        $orderDirection = $params['order_direction'] ?? 'asc';
        $query->orderBy($orderBy, $orderDirection);

        // 分页
        $page = $params['page'] ?? 1;
        $pageSize = $params['page_size'] ?? 20;
        $list = $query->paginate($pageSize, ['*'], 'page', $page);

        return [
            'list' => $list->items(),
            'total' => $list->total(),
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * 获取权限树
     */
    public function getTree(array $params = []): array
    {
        $query = Permission::query();

        if (!empty($params['type'])) {
            $query->where('type', $params['type']);
        }

        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }

        $permissions = $query->orderBy('sort', 'asc')->orderBy('id', 'asc')->get();
        return $this->buildTree($permissions);
    }

    /**
     * 获取菜单树（前端使用）
     */
    public function getMenuTree(int $userId = null): array
    {
        $query = Permission::whereIn('type', ['menu', 'api'])
            ->where('status', 1);

        if ($userId) {
            // 获取用户的权限
            $user = \App\Models\Auth\User::find($userId);
            if ($user) {
                $permissionIds = [];
                foreach ($user->roles as $role) {
                    foreach ($role->permissions as $permission) {
                        $permissionIds[] = $permission->id;
                    }
                }
                $query->whereIn('id', $permissionIds);
            }
        }

        $permissions = $query->orderBy('sort', 'asc')->orderBy('id', 'asc')->get();
        return $this->buildTree($permissions);
    }

    /**
     * 获取权限详情
     */
    public function getById(int $id): array
    {
        $permission = Permission::with(['parent'])->find($id);

        if (!$permission) {
            throw ValidationException::withMessages([
                'id' => ['权限不存在'],
            ]);
        }

        return [
            'id' => $permission->id,
            'title' => $permission->title,
            'name' => $permission->name,
            'type' => $permission->type,
            'parent_id' => $permission->parent_id,
            'parent' => $permission->parent ? [
                'id' => $permission->parent->id,
                'name' => $permission->parent->name,
            ] : null,
            'path' => $permission->path,
            'component' => $permission->component,
            'meta' => $permission->meta,
            'sort' => $permission->sort,
            'status' => $permission->status,
            'created_at' => $permission->created_at->toDateTimeString(),
            'updated_at' => $permission->updated_at->toDateTimeString(),
        ];
    }

    /**
     * 创建权限
     */
    public function create(array $data): Permission
    {
        // 检查权限标题是否已存在
        if (isset($data['title']) && Permission::where('title', $data['title'])->exists()) {
            throw ValidationException::withMessages([
                'title' => ['权限标题已存在'],
            ]);
        }

        // 检查权限编码是否已存在
        if (Permission::where('name', $data['name'])->exists()) {
            throw ValidationException::withMessages([
                'name' => ['权限编码已存在'],
            ]);
        }

        // 如果有父级ID，检查父级是否存在
        if (!empty($data['parent_id'])) {
            $parent = Permission::find($data['parent_id']);
            if (!$parent) {
                throw ValidationException::withMessages([
                    'parent_id' => ['父级权限不存在'],
                ]);
            }
        }

        return Permission::create([
            'title' => $data['title'],
            'name' => $data['name'],
            'type' => $data['type'] ?? 'menu',
            'parent_id' => $data['parent_id'] ?? 0,
            'path' => $data['path'] ?? null,
            'component' => $data['component'] ?? null,
            'meta' => $data['meta'] ?? null,
            'sort' => $data['sort'] ?? 0,
            'status' => $data['status'] ?? 1,
        ]);
    }

    /**
     * 更新权限
     */
    public function update(int $id, array $data): Permission
    {
        $permission = Permission::find($id);

        if (!$permission) {
            throw ValidationException::withMessages([
                'id' => ['权限不存在'],
            ]);
        }

        // 检查权限标题是否已被其他权限使用
        if (isset($data['title']) && $data['title'] !== $permission->title) {
            if (Permission::where('title', $data['title'])->exists()) {
                throw ValidationException::withMessages([
                    'title' => ['权限标题已存在'],
                ]);
            }
        }

        // 检查权限编码是否已被其他权限使用
        if (isset($data['name']) && $data['name'] !== $permission->name) {
            if (Permission::where('name', $data['name'])->exists()) {
                throw ValidationException::withMessages([
                    'name' => ['权限编码已存在'],
                ]);
            }
        }

        // 如果有父级ID，检查父级是否存在
        if (isset($data['parent_id']) && !empty($data['parent_id'])) {
            $parent = Permission::find($data['parent_id']);
            if (!$parent) {
                throw ValidationException::withMessages([
                    'parent_id' => ['父级权限不存在'],
                ]);
            }

            // 不能将权限设置为自己的子级
            if ($data['parent_id'] == $id) {
                throw ValidationException::withMessages([
                    'parent_id' => ['不能将权限设置为自己的子级'],
                ]);
            }
        }

        $updateData = [
            'title' => $data['title'] ?? $permission->title,
            'name' => $data['name'] ?? $permission->name,
            'type' => $data['type'] ?? $permission->type,
            'parent_id' => $data['parent_id'] ?? $permission->parent_id,
            'path' => $data['path'] ?? $permission->path,
            'component' => $data['component'] ?? $permission->component,
            'meta' => isset($data['meta']) ? $data['meta'] : $permission->meta,
            'sort' => $data['sort'] ?? $permission->sort,
            'status' => $data['status'] ?? $permission->status,
        ];

        $permission->update($updateData);
        return $permission;
    }

    /**
     * 删除权限
     */
    public function delete(int $id): void
    {
        $permission = Permission::find($id);

        if (!$permission) {
            throw ValidationException::withMessages([
                'id' => ['权限不存在'],
            ]);
        }

        // 检查是否有子权限
        if ($permission->children()->exists()) {
            throw ValidationException::withMessages([
                'id' => ['该权限下还有子权限，无法删除'],
            ]);
        }

        // 检查是否被角色使用
        if ($permission->roles()->exists()) {
            throw ValidationException::withMessages([
                'id' => ['该权限已被角色使用，无法删除'],
            ]);
        }

        $permission->delete();
    }

    /**
     * 批量删除权限
     */
    public function batchDelete(array $ids): int
    {
        // 检查是否有子权限
        $hasChildren = Permission::whereIn('id', $ids)->whereHas('children')->exists();
        if ($hasChildren) {
            throw ValidationException::withMessages([
                'ids' => ['选中的权限中还有子权限，无法删除'],
            ]);
        }

        // 检查是否被角色使用
        $hasRoles = Permission::whereIn('id', $ids)->whereHas('roles')->exists();
        if ($hasRoles) {
            throw ValidationException::withMessages([
                'ids' => ['选中的权限中已被角色使用，无法删除'],
            ]);
        }

        return Permission::whereIn('id', $ids)->delete();
    }

    /**
     * 批量更新权限状态
     */
    public function batchUpdateStatus(array $ids, int $status): int
    {
        return Permission::whereIn('id', $ids)->update(['status' => $status]);
    }

    /**
     * 构建权限树
     */
    private function buildTree($permissions, $parentId = 0): array
    {
        $tree = [];
        foreach ($permissions as $permission) {
            if ($permission->parent_id == $parentId) {
                $node = [
                    'id' => $permission->id,
                    'title' => $permission->title,
                    'name' => $permission->name,
                    'type' => $permission->type,
                    'path' => $permission->path,
                    'component' => $permission->component,
                    'meta' => $permission->meta,
                    'sort' => $permission->sort,
                    'status' => $permission->status,
                    'children' => $this->buildTree($permissions, $permission->id),
                ];
                $tree[] = $node;
            }
        }
        return $tree;
    }
}
