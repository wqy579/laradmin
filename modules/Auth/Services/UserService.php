<?php

namespace Modules\Auth\Services;

use Modules\Auth\Jobs\UserImportJob;
use Modules\Auth\Models\User;
use App\Contracts\TaskNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserService
{
    protected $departmentService;

    protected TaskNotification $notificationService;

    public function __construct(DepartmentService $departmentService, TaskNotification $notificationService)
    {
        $this->departmentService = $departmentService;
        $this->notificationService = $notificationService;
    }

    /**
     * 获取当前登录用户ID
     */
    protected function getCurrentUserId(): int
    {
        return Auth::guard('admin')->id();
    }

    /**
     * 获取用户列表
     */
    public function getList(array $params): array
    {
        $query = User::with(['department', 'roles']);

        // 搜索条件
        if (! empty($params['keyword'])) {
            $query->where(function ($q) use ($params) {
                $q->where('username', 'like', '%'.$params['keyword'].'%')
                    ->orWhere('real_name', 'like', '%'.$params['keyword'].'%')
                    ->orWhere('phone', 'like', '%'.$params['keyword'].'%')
                    ->orWhere('email', 'like', '%'.$params['keyword'].'%');
            });
        }

        // 字段筛选（浮动筛选：用户名/姓名/邮箱/手机号）
        foreach (['username', 'real_name', 'email', 'phone'] as $field) {
            if (! empty($params[$field])) {
                $query->where($field, 'like', '%'.$params[$field].'%');
            }
        }

        if (! empty($params['department_id'])) {
            // 获取部门及所有子部门的ID
            $departmentIds = $this->departmentService->getDepartmentAndChildrenIds($params['department_id']);
            $query->whereIn('department_id', $departmentIds);
        }

        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }

        if (! empty($params['role_id'])) {
            $query->whereHas('roles', function ($q) use ($params) {
                $q->where('role_id', $params['role_id']);
            });
        }

        // 排序
        $orderBy = $params['order_by'] ?? 'id';
        $orderDirection = $params['order_direction'] ?? 'desc';
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
     * 获取用户详情
     */
    public function getById(int $id): array
    {
        $user = User::with(['department', 'roles'])->find($id);

        if (! $user) {
            throw ValidationException::withMessages([
                'id' => ['用户不存在'],
            ]);
        }

        return $this->formatUserInfo($user);
    }

    /**
     * 创建用户
     */
    public function create(array $data): User
    {
        // 检查用户名是否已存在
        if (User::where('username', $data['username'])->exists()) {
            throw ValidationException::withMessages([
                'username' => ['用户名已存在'],
            ]);
        }

        // 检查手机号是否已存在
        if (! empty($data['phone']) && User::where('phone', $data['phone'])->exists()) {
            throw ValidationException::withMessages([
                'phone' => ['手机号已被使用'],
            ]);
        }

        // 检查邮箱是否已存在
        if (! empty($data['email']) && User::where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['邮箱已被使用'],
            ]);
        }

        DB::beginTransaction();
        try {
            $user = User::create([
                'username' => $data['username'],
                'password' => Hash::make($data['password']),
                'real_name' => $data['real_name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'avatar' => $data['avatar'] ?? null,
                'status' => $data['status'] ?? 1,
            ]);

            // 关联角色
            if (! empty($data['role_ids'])) {
                $user->roles()->attach($data['role_ids']);
            }

            DB::commit();

            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 更新用户
     */
    public function update(int $id, array $data): User
    {
        $user = User::find($id);

        if (! $user) {
            throw ValidationException::withMessages([
                'id' => ['用户不存在'],
            ]);
        }

        // 检查用户名是否已被其他用户使用
        if (isset($data['username']) && $data['username'] !== $user->username) {
            if (User::where('username', $data['username'])->exists()) {
                throw ValidationException::withMessages([
                    'username' => ['用户名已存在'],
                ]);
            }
        }

        // 检查手机号是否已被其他用户使用
        if (isset($data['phone']) && $data['phone'] !== $user->phone) {
            if (User::where('phone', $data['phone'])->exists()) {
                throw ValidationException::withMessages([
                    'phone' => ['手机号已被使用'],
                ]);
            }
        }

        // 检查邮箱是否已被其他用户使用
        if (isset($data['email']) && $data['email'] !== $user->email) {
            if (User::where('email', $data['email'])->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['邮箱已被使用'],
                ]);
            }
        }

        DB::beginTransaction();
        try {
            $updateData = [
                'real_name' => $data['real_name'] ?? $user->real_name,
                'email' => $data['email'] ?? $user->email,
                'phone' => $data['phone'] ?? $user->phone,
                'department_id' => $data['department_id'] ?? $user->department_id,
                'avatar' => $data['avatar'] ?? $user->avatar,
                'status' => $data['status'] ?? $user->status,
            ];

            if (isset($data['username'])) {
                $updateData['username'] = $data['username'];
            }

            if (isset($data['password'])) {
                $updateData['password'] = Hash::make($data['password']);
            }

            $user->update($updateData);

            // 更新角色关联
            if (isset($data['role_ids'])) {
                $user->roles()->sync($data['role_ids']);
            }

            DB::commit();

            // 发送更新通知（如果更新的是其他用户）
            if ($id !== $this->getCurrentUserId()) {
                $this->sendUserUpdateNotification($user, $data);
            }

            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 删除用户
     */
    public function delete(int $id): void
    {
        $user = User::find($id);

        if (! $user) {
            throw ValidationException::withMessages([
                'id' => ['用户不存在'],
            ]);
        }

        $user->delete();
    }

    /**
     * 批量删除用户
     */
    public function batchDelete(array $ids): int
    {
        $currentUserId = $this->getCurrentUserId();

        // 检查是否包含当前用户
        if (in_array($currentUserId, $ids)) {
            throw ValidationException::withMessages([
                'ids' => ['不能删除当前登录用户'],
            ]);
        }

        return User::whereIn('id', $ids)->delete();
    }

    /**
     * 批量更新用户状态
     */
    public function batchUpdateStatus(array $ids, int $status): int
    {
        $currentUserId = $this->getCurrentUserId();

        // 如果是禁用操作，检查是否包含当前用户
        if ($status === 0 && in_array($currentUserId, $ids)) {
            throw ValidationException::withMessages([
                'ids' => ['不能禁用当前登录用户'],
            ]);
        }

        return User::whereIn('id', $ids)->update(['status' => $status]);
    }

    /**
     * 批量分配部门
     */
    public function batchAssignDepartment(array $ids, int $departmentId): int
    {
        return User::whereIn('id', $ids)->update(['department_id' => $departmentId]);
    }

    /**
     * 批量分配角色
     */
    public function batchAssignRoles(array $ids, array $roleIds): void
    {
        foreach ($ids as $userId) {
            $user = User::find($userId);
            if ($user) {
                $user->roles()->sync($roleIds);
            }
        }
    }

    /**
     * 导出用户数据
     */
    public function export(array $params): array
    {
        $taskId = (string) Str::uuid();
        $params['task_id'] = $taskId;

        // 获取当前用户ID（队列任务执行时 Auth 不再可用）
        $currentUserId = auth('admin')->id() ?? 0;

        // 防重复提交：使用 Cache 检查最近是否有相同用户的导出请求
        $cacheKey = 'user_export_lock:'.$currentUserId;
        if (Cache::has($cacheKey)) {
            return [
                'message' => '导出任务正在处理中，请稍后再试',
                'task_id' => Cache::get($cacheKey.'_task_id', $taskId),
            ];
        }

        // 设置锁，30秒内防止重复提交
        Cache::put($cacheKey, true, 30);
        Cache::put($cacheKey.'_task_id', $taskId, 30);

        $job = new \Modules\Auth\Jobs\UserExportJob($params, $currentUserId);
        dispatch($job);

        return [
            'message' => '导出任务已提交，完成后将通过系统通知发送下载链接',
            'task_id' => $taskId,
        ];
    }

    /**
     * 导入用户数据
     */
    public function import(UploadedFile $file): array
    {
        $path = $file->store('imports');

        // 异步导入
        $job = new UserImportJob($path);
        dispatch($job);

        return [
            'message' => '导入任务已提交，请稍后在导入列表中查看',
            'file_path' => $path,
        ];
    }

    /**
     * 格式化用户信息
     */
    private function formatUserInfo(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'real_name' => $user->real_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar' => $user->avatar,
            'department' => $user->department ? [
                'id' => $user->department->id,
                'name' => $user->department->name,
            ] : null,
            'roles' => $user->roles->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'code' => $role->code,
                ];
            })->toArray(),
            'status' => $user->status,
            'last_login_at' => $user->last_login_at ? $user->last_login_at->toDateTimeString() : null,
            'last_login_ip' => $user->last_login_ip,
            'created_at' => $user->created_at->toDateTimeString(),
            'updated_at' => $user->updated_at->toDateTimeString(),
        ];
    }

    /**
     * 发送用户更新通知
     */
    private function sendUserUpdateNotification(User $user, array $data): void
    {
        // 收集被更新的字段
        $changes = [];

        $fieldLabels = [
            'username' => '用户名',
            'real_name' => '姓名',
            'email' => '邮箱',
            'phone' => '手机号',
            'department_id' => '所属部门',
            'avatar' => '头像',
            'status' => '状态',
            'password' => '密码',
            'role_ids' => '角色',
        ];

        foreach ($data as $key => $value) {
            if (isset($fieldLabels[$key])) {
                $changes[] = $fieldLabels[$key];
            }
        }

        if (empty($changes)) {
            return;
        }

        // 生成通知内容
        $content = '您的账户信息已被管理员更新，更新的内容：'.implode('、', $changes);

        // 发送通知
        $this->notificationService->sendToUser(
            $user->id,
            '个人信息已更新',
            $content,
            TaskNotification::TYPE_INFO,
            TaskNotification::CATEGORY_SYSTEM,
            [
                'user_id' => $user->id,
                'updated_fields' => $changes,
                'action_type' => TaskNotification::ACTION_NONE,
            ]
        );
    }
}
