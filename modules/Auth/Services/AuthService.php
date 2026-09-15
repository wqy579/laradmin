<?php

namespace Modules\Auth\Services;

use Modules\Auth\Models\User;
use Modules\Auth\Services\PermissionService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
	protected $permissionService;

	public function __construct(PermissionService $permissionService)
	{
		$this->permissionService = $permissionService;
	}

	/**
	 * 管理员登录
	 */
	public function login(array $credentials): array
	{
		$user = User::where('username', $credentials['username'])->first();

		if (!$user || !Hash::check($credentials['password'], $user->password)) {
			throw ValidationException::withMessages([
				'username' => ['用户名或密码错误'],
			]);
		}

		if ($user->status !== 1) {
			throw ValidationException::withMessages([
				'username' => ['账号已被禁用'],
			]);
		}

		// 更新登录信息
		$user->update([
			'last_login_at' => now(),
			'last_login_ip' => request()->ip(),
		]);

		// 生成token
		$token = auth('admin')->login($user);

		// 获取用户菜单
		$menu = $this->getUserMenu($user);

		// 获取用户权限列表
		$permissions = $this->getUserPermissions($user);

		return [
			'token' => $token,
			'expires_in' => auth('admin')->factory()->getTTL() * 60,
			'user' => $this->getUserInfo($user),
			'menu' => $menu,
			'permissions' => $permissions,
		];
	}

	/**
	 * 管理员登出
	 */
	public function logout(): void
	{
		auth('admin')->logout();
	}

	/**
	 * 刷新token
	 */
	public function refresh(): array
	{
		// 只刷一次：auth('admin')->refresh() 会把当前 token 立刻拉黑（jwt 黑名单零宽限）
		// 并签发新 token，第二次调用会对同一个旧 token 再 decode 一次，命中黑名单抛
		// TokenBlacklistedException，导致 POST /admin/auth/refresh 在任何环境下都 401。
		// 这里不再二次调用；refreshToken 保留为同一个新 token 的别名，兼容老前端契约。
		$newToken = auth('admin')->refresh();
		$user = auth('admin')->user();
		$newRefreshToken = $newToken;

		// 获取用户菜单
		$menu = $this->getUserMenu($user);

		// 获取用户权限列表
		$permissions = $this->getUserPermissions($user);

		return [
			'token' => $newToken,
			'refreshToken' => $newRefreshToken,
			'user' => $this->getUserInfo($user),
			'menu' => $menu,
			'permissions' => $permissions,
		];
	}

    /**
     * 获取当前用户信息
     */
    public function me(): array
    {
        $user = auth('admin')->user();
        return $this->getUserInfo($user);
    }

    /**
     * 更新个人资料
     */
    public function updateProfile(array $data): array
    {
        $user = auth('admin')->user();
        $fillable = [];

        if (isset($data['username'])) {
            $fillable['username'] = $data['username'];
        }
        if (isset($data['real_name'])) {
            $fillable['real_name'] = $data['real_name'];
        }
        if (isset($data['phone'])) {
            $fillable['phone'] = $data['phone'];
        }
        if (isset($data['email'])) {
            $fillable['email'] = $data['email'];
        }

        if (!empty($fillable)) {
            $user->update($fillable);
        }

        return $this->getUserInfo($user);
    }

	/**
	 * 找回密码
	 */
	public function resetPassword(array $data): void
	{
		$user = User::where('username', $data['username'])
			->orWhere('email', $data['username'])
			->orWhere('phone', $data['username'])
			->first();

		if (!$user) {
			throw ValidationException::withMessages([
				'username' => ['用户不存在'],
			]);
		}

		$user->update([
			'password' => Hash::make($data['password']),
		]);
	}

	/**
	 * 修改密码
	 */
	public function changePassword(array $data): void
	{
		$user = auth('admin')->user();

		if (!Hash::check($data['old_password'], $user->password)) {
			throw ValidationException::withMessages([
				'old_password' => ['原密码错误'],
			]);
		}

		$user->update([
			'password' => Hash::make($data['password']),
		]);
	}

	/**
	 * 获取用户信息详情
	 */
	private function getUserInfo(User $user): array
	{
		$user->load(['department', 'roles.permissions']);

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
			'roles' => $user->roles->pluck('name')->toArray(),
			'permissions' => $this->getUserPermissions($user),
			'status' => $user->status,
			'last_login_at' => $user->last_login_at ? $user->last_login_at->toDateTimeString() : null,
		];
	}

	/**
	 * 获取用户菜单
	 */
	public function getUserMenu(User $user): array
	{
		// 超级管理员获取所有菜单
		if ($user->isSuperAdmin()) {
			$menuPermissions = \Modules\Auth\Models\Permission::where('type', 'menu')
				->where('status', 1)
				->orderBy('sort', 'asc')
				->get();
		} else {
			// 获取用户的所有权限
			$permissionIds = [];
			foreach ($user->roles as $role) {
				foreach ($role->permissions as $permission) {
					$permissionIds[] = $permission->id;
				}
			}

			// 查询菜单类型的权限
			$menuPermissions = \Modules\Auth\Models\Permission::whereIn('id', $permissionIds)
				->where('type', 'menu')
				->where('status', 1)
				->orderBy('sort', 'asc')
				->get();
		}

		// 构建菜单树
		return $this->buildMenuTree($menuPermissions);
	}

	/**
	 * 构建菜单树
	 */
	private function buildMenuTree($permissions, $parentId = 0): array
	{
		$tree = [];
		foreach ($permissions as $permission) {
			if ($permission->parent_id == $parentId) {
				$node = [
					'path' => $permission->path,
					'name' => $permission->name,
					'title' => $permission->title,
					'meta' => $permission->meta ?: [],
				];

				// 添加组件路径
				if ($permission->component) {
					$node['component'] = $permission->component;
				}

				// 添加重定向
				if (!empty($node['meta']['redirect'])) {
					$node['redirect'] = $node['meta']['redirect'];
				}

				// 递归构建子菜单
				$children = $this->buildMenuTree($permissions, $permission->id);
				if (!empty($children)) {
					$node['children'] = $children;
				}

				$tree[] = $node;
			}
		}
		return $tree;
	}

	/**
	 * 获取用户权限列表
	 */
	private function getUserPermissions(User $user): array
	{
		// 超级管理员获取所有权限
		if ($user->isSuperAdmin()) {
			return \Modules\Auth\Models\Permission::where('status', 1)
				->pluck('name')
				->toArray();
		}

		$permissions = [];
		foreach ($user->roles as $role) {
			foreach ($role->permissions as $permission) {
				if (!in_array($permission->name, $permissions)) {
					$permissions[] = $permission->name;
				}
			}
		}
		return $permissions;
	}
}
