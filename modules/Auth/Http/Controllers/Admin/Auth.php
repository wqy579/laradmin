<?php

namespace Modules\Auth\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Modules\Auth\Http\Requests\AuthRequest;
use Modules\Auth\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
        } catch (ValidationException $e) {
            // 凭证错误是 401，不是服务器错误。此前被 catch(Exception) 吞成 500：
            // 密码敲错一次就在 5xx 监控里记一条故障，监控上业务失败全被算成事故。
            return response()->json([
                'code' => 401,
                'message' => '登录失败：' . $this->validationMessage($e),
                'data' => null,
            ], 401);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '登录失败：' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * 把 ValidationException 的字段错误拍平成一句可直接展示给用户的中文提示
     *
     * 服务层用 ValidationException::withMessages 抛业务拒绝（密码错、账号禁用、原密码错），
     * errors() 的形状是 ['username' => ['用户名或密码错误']]，不能直接当 message 返回。
     */
    private function validationMessage(ValidationException $e): string
    {
        return collect($e->errors())
            ->flatten(2)
            ->implode('；');
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
        } catch (ValidationException $e) {
            // 「用户不存在」是输入问题，不是服务器错误
            return response()->json([
                'code' => 422,
                'message' => '密码重置失败：' . $this->validationMessage($e),
                'data' => null,
            ], 422);
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
        } catch (ValidationException $e) {
            // 「原密码错误」是校验失败，不是服务器错误
            return response()->json([
                'code' => 422,
                'message' => '密码修改失败：' . $this->validationMessage($e),
                'data' => null,
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '密码修改失败：' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }
}
