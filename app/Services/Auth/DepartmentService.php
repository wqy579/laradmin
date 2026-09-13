<?php

namespace App\Services\Auth;

use App\Models\Auth\Department;
use Illuminate\Validation\ValidationException;

class DepartmentService
{
    /**
     * 获取部门列表
     */
    public function getList(array $params): array
    {
        $query = Department::query();

        // 搜索条件
        if (!empty($params['keyword'])) {
            $query->where(function ($q) use ($params) {
                $q->where('name', 'like', '%' . $params['keyword'] . '%')
                    ->orWhere('leader', 'like', '%' . $params['keyword'] . '%')
                    ->orWhere('phone', 'like', '%' . $params['keyword'] . '%');
            });
        }

        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }

        if (isset($params['parent_id']) && $params['parent_id'] !== '') {
            $query->where('parent_id', $params['parent_id']);
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
     * 获取部门树
     */
    public function getTree(array $params = []): array
    {
        $query = Department::query();

        // 搜索条件
        if (!empty($params['keyword'])) {
            $query->where(function ($q) use ($params) {
                $q->where('name', 'like', '%' . $params['keyword'] . '%')
                    ->orWhere('leader', 'like', '%' . $params['keyword'] . '%');
            });
        }

        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }

        $departments = $query->orderBy('sort', 'asc')->get();
        return $this->buildTree($departments);
    }

    /**
     * 获取所有部门（不分页）
     */
    public function getAll(): array
    {
        $departments = Department::where('status', 1)->orderBy('sort', 'asc')->get();
        return $departments->map(function ($department) {
            return [
                'id' => $department->id,
                'name' => $department->name,
                'parent_id' => $department->parent_id,
            ];
        })->toArray();
    }

    /**
     * 获取部门详情
     */
    public function getById(int $id): array
    {
        $department = Department::with(['parent', 'children'])->find($id);

        if (!$department) {
            throw ValidationException::withMessages([
                'id' => ['部门不存在'],
            ]);
        }

        return [
            'id' => $department->id,
            'name' => $department->name,
            'parent_id' => $department->parent_id,
            'parent' => $department->parent ? [
                'id' => $department->parent->id,
                'name' => $department->parent->name,
            ] : null,
            'leader' => $department->leader,
            'phone' => $department->phone,
            'sort' => $department->sort,
            'status' => $department->status,
            'children_count' => $department->children()->count(),
            'users_count' => $department->users()->count(),
            'created_at' => $department->created_at->toDateTimeString(),
            'updated_at' => $department->updated_at->toDateTimeString(),
        ];
    }

    /**
     * 创建部门
     */
    public function create(array $data): Department
    {
        // 检查部门名称是否已存在
        $query = Department::where('name', $data['name']);
        if (!empty($data['parent_id'])) {
            $query->where('parent_id', $data['parent_id']);
        } else {
            $query->where('parent_id', 0);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => ['同级部门名称已存在'],
            ]);
        }

        // 如果有父级ID，检查父级是否存在
        if (!empty($data['parent_id'])) {
            $parent = Department::find($data['parent_id']);
            if (!$parent) {
                throw ValidationException::withMessages([
                    'parent_id' => ['父级部门不存在'],
                ]);
            }
        }

        return Department::create([
            'name' => $data['name'],
            'parent_id' => $data['parent_id'] ?? 0,
            'leader' => $data['leader'] ?? null,
            'phone' => $data['phone'] ?? null,
            'sort' => $data['sort'] ?? 0,
            'status' => $data['status'] ?? 1,
        ]);
    }

    /**
     * 更新部门
     */
    public function update(int $id, array $data): Department
    {
        $department = Department::find($id);

        if (!$department) {
            throw ValidationException::withMessages([
                'id' => ['部门不存在'],
            ]);
        }

        // 检查部门名称是否已被其他部门使用
        if (isset($data['name']) && $data['name'] !== $department->name) {
            $query = Department::where('name', $data['name'])
                ->where('id', '!=', $id);
            $parentId = isset($data['parent_id']) ? $data['parent_id'] : $department->parent_id;
            if ($parentId) {
                $query->where('parent_id', $parentId);
            } else {
                $query->where('parent_id', 0);
            }
            if ($query->exists()) {
                throw ValidationException::withMessages([
                    'name' => ['同级部门名称已存在'],
                ]);
            }
        }

        // 如果有父级ID，检查父级是否存在
        if (isset($data['parent_id']) && !empty($data['parent_id'])) {
            $parent = Department::find($data['parent_id']);
            if (!$parent) {
                throw ValidationException::withMessages([
                    'parent_id' => ['父级部门不存在'],
                ]);
            }

            // 不能将部门设置为自己的子级
            if ($data['parent_id'] == $id) {
                throw ValidationException::withMessages([
                    'parent_id' => ['不能将部门设置为自己的子级'],
                ]);
            }

            // 不能将部门设置为自己的子孙级
            if ($this->isDescendant($id, $data['parent_id'])) {
                throw ValidationException::withMessages([
                    'parent_id' => ['不能将部门设置为自己的子孙级'],
                ]);
            }
        }

        $updateData = [
            'name' => $data['name'] ?? $department->name,
            'parent_id' => $data['parent_id'] ?? $department->parent_id,
            'leader' => $data['leader'] ?? $department->leader,
            'phone' => $data['phone'] ?? $department->phone,
            'sort' => $data['sort'] ?? $department->sort,
            'status' => $data['status'] ?? $department->status,
        ];

        $department->update($updateData);
        return $department;
    }

    /**
     * 删除部门
     */
    public function delete(int $id): void
    {
        $department = Department::find($id);

        if (!$department) {
            throw ValidationException::withMessages([
                'id' => ['部门不存在'],
            ]);
        }

        // 检查是否有子部门
        if ($department->children()->exists()) {
            throw ValidationException::withMessages([
                'id' => ['该部门下还有子部门，无法删除'],
            ]);
        }

        // 检查部门下是否有用户
        if ($department->users()->exists()) {
            throw ValidationException::withMessages([
                'id' => ['该部门下还有用户，无法删除'],
            ]);
        }

        $department->delete();
    }

    /**
     * 批量删除部门
     */
    public function batchDelete(array $ids): int
    {
        // 检查是否有子部门
        $hasChildren = Department::whereIn('id', $ids)->whereHas('children')->exists();
        if ($hasChildren) {
            throw ValidationException::withMessages([
                'ids' => ['选中的部门中还有子部门，无法删除'],
            ]);
        }

        // 检查部门下是否有用户
        $hasUsers = Department::whereIn('id', $ids)->whereHas('users')->exists();
        if ($hasUsers) {
            throw ValidationException::withMessages([
                'ids' => ['选中的部门中还有用户，无法删除'],
            ]);
        }

        return Department::whereIn('id', $ids)->delete();
    }

    /**
     * 批量更新部门状态
     */
    public function batchUpdateStatus(array $ids, int $status): int
    {
        return Department::whereIn('id', $ids)->update(['status' => $status]);
    }

    /**
     * 构建部门树
     */
    private function buildTree($departments, $parentId = 0): array
    {
        $tree = [];
        foreach ($departments as $department) {
            if ($department->parent_id == $parentId) {
                $node = [
                    'id' => $department->id,
                    'name' => $department->name,
                    'parent_id' => $department->parent_id,
                    'leader' => $department->leader,
                    'phone' => $department->phone,
                    'sort' => $department->sort,
                    'status' => $department->status,
                    'children' => $this->buildTree($departments, $department->id),
                ];
                $tree[] = $node;
            }
        }
        return $tree;
    }

    /**
     * 检查是否为子孙部门
     */
    private function isDescendant($id, $childId): bool
    {
        if ($id == $childId) {
            return true;
        }
        $child = Department::find($childId);
        if (!$child || $child->parent_id == 0) {
            return false;
        }
        return $this->isDescendant($id, $child->parent_id);
    }

    /**
     * 获取部门及所有子部门的ID列表
     *
     * @param int $departmentId 部门ID
     * @param array $departments 所有部门数据
     * @return array
     */
    public function getDepartmentAndChildrenIds(int $departmentId, array $departments = null): array
    {
        if ($departments === null) {
            $departments = Department::where('status', 1)->get()->keyBy('id')->toArray();
        }

        $ids = [$departmentId];
        $this->collectChildrenIds($departmentId, $departments, $ids);

        return $ids;
    }

    /**
     * 递归收集子部门ID
     *
     * @param int $parentId 父部门ID
     * @param array $departments 所有部门数据
     * @param array &$ids ID收集数组
     */
    private function collectChildrenIds(int $parentId, array $departments, array &$ids): void
    {
        foreach ($departments as $department) {
            if ($department['parent_id'] == $parentId) {
                $ids[] = $department['id'];
                $this->collectChildrenIds($department['id'], $departments, $ids);
            }
        }
    }
}
