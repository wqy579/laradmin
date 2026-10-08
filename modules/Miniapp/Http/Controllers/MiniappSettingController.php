<?php

namespace Modules\Miniapp\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 模块十：小程序设置（小程序管理 → 小程序设置）
 *
 * 9 组配置共用一套读写逻辑：读 = 默认值与库里已存值做浅合并，
 * 写 = 整组覆盖。默认值在这里集中定义，前端表单的字段名与之一一对应。
 *
 * 敏感字段（AppSecret、支付 API 密钥）不在读接口里返回明文：
 * 用 '******' 占位，前端要改就重新填，填了才更新——避免密钥被浏览器历史、
 * 截图、日志一路带出去。
 */
class MiniappSettingController extends Controller
{
    /** 需要在响应里打码的字段 */
    private const SECRET_FIELDS = ['app_secret', 'api_key'];

    private const GROUPS = ['basic', 'home', 'category', 'payment', 'notify', 'delivery', 'member', 'coupon', 'about'];

    public function index(Request $request): JsonResponse
    {
        $group = (string) $request->input('group', 'basic');
        $stored = $this->stored($group);
        $config = array_merge($this->defaults($group), $stored);

        foreach (self::SECRET_FIELDS as $field) {
            if (isset($config[$field]) && $config[$field] !== '') {
                $config[$field] = '******';
            }
        }

        return $this->success([
            'group' => $group,
            'config' => $config,
            'groups' => $this->groupMeta(),
            // 支付回调地址由系统拼出，不允许手改（改了收不到微信回调）
            'readonly' => ['notify_url' => url('/api/miniapp/pay/notify')],
        ]);
    }

    public function update(Request $request, string $group): JsonResponse
    {
        if (! in_array($group, self::GROUPS, true)) {
            return $this->error('未知的配置分组', 422);
        }

        $config = $request->input('config');
        if (! is_array($config)) {
            return $this->validateError(['config' => ['配置内容格式不正确']]);
        }

        $merged = array_merge($this->stored($group), $config);

        // 打码值视为「没改」：前端把 ****** 原样回传时不能覆盖库里的真值
        foreach (self::SECRET_FIELDS as $field) {
            if (($merged[$field] ?? null) === '******') {
                $merged[$field] = $this->stored($group)[$field] ?? '';
            }
        }

        DB::table('miniapp_settings')->updateOrInsert(
            ['group' => $group],
            [
                'config' => json_encode($merged, JSON_UNESCAPED_UNICODE),
                'updated_by' => (int) auth('admin')->id(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return $this->success(null, '设置已保存');
    }

    /** 恢复某组默认值 */
    public function reset(string $group): JsonResponse
    {
        if (! in_array($group, self::GROUPS, true)) {
            return $this->error('未知的配置分组', 422);
        }

        DB::table('miniapp_settings')->where('group', $group)->delete();

        return $this->success(null, '已恢复默认设置');
    }

    private function stored(string $group): array
    {
        $row = DB::table('miniapp_settings')->where('group', $group)->value('config');
        $decoded = $row ? json_decode($row, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /** 左侧菜单：9 组配置的中文名与顺序 */
    private function groupMeta(): array
    {
        return [
            ['key' => 'basic', 'title' => '基本设置'],
            ['key' => 'home', 'title' => '首页装修'],
            ['key' => 'category', 'title' => '商品分类'],
            ['key' => 'payment', 'title' => '支付设置'],
            ['key' => 'notify', 'title' => '消息推送'],
            ['key' => 'delivery', 'title' => '配送设置'],
            ['key' => 'member', 'title' => '会员设置'],
            ['key' => 'coupon', 'title' => '优惠券设置'],
            ['key' => 'about', 'title' => '关于我们'],
        ];
    }

    /**
     * 各组默认值
     *
     * 首页装修的 components 是空数组：装修结果是「拖了什么就是什么」，
     * 给一堆预置组件会让用户第一眼分不清哪些是自己配的。
     */
    private function defaults(string $group): array
    {
        return match ($group) {
            'basic' => [
                'name' => '',
                'app_id' => '',
                'app_secret' => '',
                'icon' => '',
                'intro' => '',
                'service_phone' => '',
                'service_wechat' => '',
                'request_domain' => '',
                'socket_domain' => '',
                'upload_domain' => '',
                'download_domain' => '',
            ],
            'home' => [
                'components' => [],
                'nav_title' => '',
            ],
            'category' => [
                'categories' => [],
                'show_category_tab' => true,
            ],
            'payment' => [
                'mch_id' => '',
                'api_key' => '',
                'cert_path' => '',
                'notify_url' => url('/api/miniapp/pay/notify'),
                'enable_wechat' => true,
                'enable_balance' => false,
                'enable_points' => false,
                'points_ratio' => 100,
            ],
            'notify' => [
                'templates' => [
                    ['key' => 'paid', 'name' => '订单支付成功通知', 'enabled' => false, 'template_id' => ''],
                    ['key' => 'shipped', 'name' => '订单发货通知', 'enabled' => false, 'template_id' => ''],
                    ['key' => 'received', 'name' => '订单收货通知', 'enabled' => false, 'template_id' => ''],
                    ['key' => 'refunded', 'name' => '退款成功通知', 'enabled' => false, 'template_id' => ''],
                    ['key' => 'coupon', 'name' => '优惠券到账通知', 'enabled' => false, 'template_id' => ''],
                    ['key' => 'promotion', 'name' => '促销活动通知', 'enabled' => false, 'template_id' => ''],
                ],
            ],
            'delivery' => [
                'delivery_mode' => 'self',
                'free_shipping_amount' => 0,
                'shipping_fee' => 0,
                'delivery_range_km' => 0,
                'support_pickup' => false,
                'pickup_address' => '',
            ],
            'member' => [
                'enable_member' => false,
                'default_level' => '普通会员',
                'levels' => [],
                'points_per_yuan' => 1,
            ],
            'coupon' => [
                'enable_coupon' => false,
                'max_usable_per_order' => 1,
                'allow_stack' => false,
                'coupons' => [],
            ],
            'about' => [
                'content' => '',
            ],
            default => [],
        };
    }
}
