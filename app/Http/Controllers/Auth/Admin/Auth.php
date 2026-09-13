<?php

namespace App\Http\Controllers\Auth\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AuthRequest;
use App\Services\Auth\AuthService;
use Illuminate\Http\Request;
use Exception;

class Auth extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * 管理员登录
     */
    public function login(AuthRequest $request)
    {
        try {
            $validated = $request->validated();

            $result = $this->authService->login($validated);

            return response()->json([
                'code' => 200,
                'message' => '登录成功',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '登录失败：' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * 管理员登出
     */
    public function logout(Request $request)
    {
        try {
            $this->authService->logout();

            return response()->json([
                'code' => 200,
                'message' => '登出成功',
                'data' => null,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '登出失败：' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * 刷新token
     */
    public function refresh(Request $request)
    {
        try {
            $result = $this->authService->refresh();

            return response()->json([
                'code' => 200,
                'message' => '刷新成功',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 401,
                'message' => 'Token无效或已过期',
                'data' => null,
            ], 401);
        }
    }

    /**
     * 获取当前用户信息
     */
    public function me(Request $request)
    {
        try {
            $result = $this->authService->me();

            return response()->json([
                'code' => 200,
                'message' => 'success',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 401,
                'message' => '未登录或token已过期',
                'data' => null,
            ], 401);
        }
    }


    /**
     * 获取当前用户菜单（前端刷新后重新拉取，修复刷新掉登录）
     */
    public function menu(Request $request)
    {
        try {
            $user = auth('admin')->user();
            $menu = $this->authService->getUserMenu($user);

            return response()->json([
                'code' => 200,
                'message' => 'success',
                'data' => $menu,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 401,
                'message' => '未登录或token已过期',
                'data' => null,
            ], 401);
        }
    }
    /**
     * 更新个人资料
     */
    public function updateMe(AuthRequest $request)
    {
        try {
            $validated = $request->validated();

            $result = $this->authService->updateProfile($validated);

            return response()->json([
                'code' => 200,
                'message' => '保存成功',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '保存失败：' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * 找回密码
     */
    public function resetPassword(AuthRequest $request)
    {
        try {
            $validated = $request->validated();

            $this->authService->resetPassword($validated);

            return response()->json([
                'code' => 200,
                'message' => '密码重置成功',
                'data' => null,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '密码重置失败：' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * 修改密码
     */
    public function changePassword(AuthRequest $request)
    {
        try {
            $validated = $request->validated();

            $this->authService->changePassword($validated);

            return response()->json([
                'code' => 200,
                'message' => '密码修改成功',
                'data' => null,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '密码修改失败：' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }
}
