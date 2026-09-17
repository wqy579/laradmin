<?php

namespace Modules\Auth\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Models\Role;

class RoleService
{
    /**
     * 获取角色列表
     */
    public function getList(array $params): array
    {
        $query = Role::query();

        // 搜索条件
        if (! empty($params['keyword'])) {
            $query->where(function ($q) use ($params) {
                $q->where('name', 'like', '%'.$params['keyword'].'%')
                    ->orWhere('code', 'like', '%'.$params['keyword'].'%');
            });
        }

        // 按角色名称搜索（浮动筛选用）
        if (! empty($params['name'])) {
            $query->where('name', 'like', '%'.$params['name'].'%');
        }

        // 按角色标识搜索（浮动筛选用）
        if (! empty($params['code'])) {
            $query->where('code', 'like', '%'.$params['code'].'%');
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
     * 获取所有角色（不分页）
     */
    public function getAll(): array
    {
        $roles = Role::where('status', 1)->orderBy('sort', 'asc')->get();

        return $roles->map(function ($role) {
            return [
                'id' => $role->id,
                'name' => $role->name,
                'code' => $role->code,
            ];
        })->toArray();
    }

    /**
     * 获取角色详情
     */
    public function getById(int $id): array
    {
        $role = Role::with(['permissions'])->find($id);

        if (! $role) {
            throw ValidationException::withMessages([
                'id' => ['角色不存在'],
            ]);
        }

        return [
            'id' => $role->id,
            'name' => $role->name,
            'code' => $role->code,
            'description' => $role->description,
            'sort' => $role->sort,
            'status' => $role->status,
            'permissions' => $role->permissions->pluck('id')->toArray(),
            'created_at' => $role->created_at->toDateTimeString(),
            'updated_at' => $role->updated_at->toDateTimeString(),
        ];
    }

    /**
     * 创建角色
     */
    public function create(array $data): Role
    {
        // 检查角色名称是否已存在
        if (Role::where('name', $data['name'])->exists()) {
            throw ValidationException::withMessages([
                'name' => ['角色名称已存在'],
            ]);
        }

        // 检查角色编码是否已存在
        if (Role::where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages([
                'code' => ['角色编码已存在'],
            ]);
        }

        DB::beginTransaction();
        try {
            $role = Role::create([
                'name' => $data['name'],
                'code' => $data['code'],
                'description' => $data['description'] ?? null,
                'sort' => $data['sort'] ?? 0,
                'status' => $data['status'] ?? 1,
            ]);

            // 关联权限
            if (! empty($data['permission_ids'])) {
                $role->permissions()->attach($data['permission_ids']);
            }

            DB::commit();

            return $role;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 更新角色
     */
    public function update(int $id, array $data): Role
    {
        $role = Role::find($id);

        if (! $role) {
            throw ValidationException::withMessages([
                'id' => ['角色不存在'],
            ]);
        }

        // 检查角色名称是否已被其他角色使用
        if (isset($data['name']) && $data['name'] !== $role->name) {
            if (Role::where('name', $data['name'])->exists()) {
                throw ValidationException::withMessages([
                    'name' => ['角色名称已存在'],
                ]);
            }
        }

        // 检查角色编码是否已被其他角色使用
        if (isset($data['code']) && $data['code'] !== $role->code) {
            if (Role::where('code', $data['code'])->exists()) {
                throw ValidationException::withMessages([
                    'code' => ['角色编码已存在'],
                ]);
            }
        }

        DB::beginTransaction();
        try {
            $updateData = [
                'name' => $data['name'] ?? $role->name,
                'code' => $data['code'] ?? $role->code,
                'description' => $data['description'] ?? $role->description,
                'sort' => $data['sort'] ?? $role->sort,
                'status' => $data['status'] ?? $role->status,
            ];

            $role->update($updateData);

            // 更新权限关联
            if (isset($data['permission_ids'])) {
                $role->permissions()->sync($data['permission_ids']);
            }

            DB::commit();

            return $role;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 删除角色
     */
    public function delete(int $id): void
    {
        $role = Role::find($id);

        if (! $role) {
            throw ValidationException::withMessages([
                'id' => ['角色不存在'],
            ]);
        }

        // 检查角色下是否有用户
        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'id' => ['该角色下还有用户，无法删除'],
            ]);
        }

        $role->delete();
    }

    /**
     * 批量删除角色
     */
    public function batchDelete(array $ids): int
    {
        // 检查角色下是否有用户
        $hasUsers = Role::whereIn('id', $ids)->whereHas('users')->exists();
        if ($hasUsers) {
            throw ValidationException::withMessages([
                'ids' => ['选中的角色中还有用户，无法删除'],
            ]);
        }

        return Role::whereIn('id', $ids)->delete();
    }

    /**
     * 批量更新角色状态
     */
    public function batchUpdateStatus(array $ids, int $status): int
    {
        return Role::whereIn('id', $ids)->update(['status' => $status]);
    }

    /**
     * 分配权限
     */
    public function assignPermissions(int $id, array $permissionIds): void
    {
        $role = Role::find($id);

        if (! $role) {
            throw ValidationException::withMessages([
                'id' => ['角色不存在'],
            ]);
        }

        $role->permissions()->sync($permissionIds);
    }

    /**
     * 获取角色的权限列表
     */
    public function getPermissions(int $id): array
    {
        $role = Role::with(['permissions' => function ($query) {
            $query->orderBy('sort', 'asc');
        }])->find($id);

        if (! $role) {
            throw ValidationException::withMessages([
                'id' => ['角色不存在'],
            ]);
        }

        return $this->buildPermissionTree($role->permissions);
    }

    /**
     * 复制角色
     */
    public function copy(int $id, array $data): Role
    {
        $sourceRole = Role::with(['permissions'])->find($id);

        if (! $sourceRole) {
            throw ValidationException::withMessages([
                'id' => ['角色不存在'],
            ]);
        }

        // 检查新角色名称是否已存在
        if (Role::where('name', $data['name'])->exists()) {
            throw ValidationException::withMessages([
                'name' => ['角色名称已存在'],
            ]);
        }

        // 检查新角色编码是否已存在
        if (Role::where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages([
                'code' => ['角色编码已存在'],
            ]);
        }

        DB::beginTransaction();
        try {
            // 创建新角色
            $newRole = Role::create([
                'name' => $data['name'],
                'code' => $data['code'],
                'description' => $data['description'] ?? $sourceRole->description,
                'sort' => $data['sort'] ?? $sourceRole->sort,
                'status' => $data['status'] ?? 1,
            ]);

            // 复制权限
            $permissionIds = $sourceRole->permissions->pluck('id')->toArray();
            if (! empty($permissionIds)) {
                $newRole->permissions()->attach($permissionIds);
            }

            DB::commit();

            return $newRole;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 批量复制角色
     */
    public function batchCopy(array $roleIds, array $data): array
    {
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        $newRoles = [];

        DB::beginTransaction();
        try {
            foreach ($roleIds as $index => $roleId) {
                try {
                    $sourceRole = Role::with(['permissions'])->find($roleId);

                    if (! $sourceRole) {
                        $errors[] = "角色ID {$roleId} 不存在";
                        $errorCount++;

                        continue;
                    }

                    // 生成新的名称和编码
                    $newName = $data['name'] ?? ($sourceRole->name.' (副本)');
                    $newCode = $data['code'] ?? ($sourceRole->code.'_copy_'.time());

                    // 检查名称和编码是否已存在
                    $nameSuffix = '';
                    $codeSuffix = '';
                    $counter = 1;

                    while (Role::where('name', $newName.$nameSuffix)->exists()) {
                        $nameSuffix = ' ('.$counter.')';
                        $counter++;
                    }

                    $counter = 1;
                    while (Role::where('code', $newCode.$codeSuffix)->exists()) {
                        $codeSuffix = '_'.$counter;
                        $counter++;
                    }

                    // 创建新角色
                    $newRole = Role::create([
                        'name' => $newName.$nameSuffix,
                        'code' => $newCode.$codeSuffix,
                        'description' => $data['description'] ?? $sourceRole->description,
                        'sort' => $data['sort'] ?? $sourceRole->sort,
                        'status' => $data['status'] ?? 1,
                    ]);

                    // 复制权限
                    $permissionIds = $sourceRole->permissions->pluck('id')->toArray();
                    if (! empty($permissionIds)) {
                        $newRole->permissions()->attach($permissionIds);
                    }

                    $newRoles[] = [
                        'id' => $newRole->id,
                        'name' => $newRole->name,
                        'code' => $newRole->code,
                    ];

                    $successCount++;
                } catch (\Exception $e) {
                    $errors[] = "复制角色ID {$roleId} 失败：".$e->getMessage();
                    $errorCount++;
                }
            }

            DB::commit();

            return [
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'errors' => $errors,
                'new_roles' => $newRoles,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 构建权限树
     */
    private function buildPermissionTree($permissions, $parentId = 0): array
    {
        $tree = [];
        foreach ($permissions as $permission) {
            if ($permission->parent_id == $parentId) {
                $node = [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'code' => $permission->code,
                    'type' => $permission->type,
                    'route' => $permission->route,
                    'component' => $permission->component,
                    'meta' => $permission->meta,
                    'sort' => $permission->sort,
                    'status' => $permission->status,
                    'children' => $this->buildPermissionTree($permissions, $permission->id),
                ];
                $tree[] = $node;
            }
        }

        return $tree;
    }
}
