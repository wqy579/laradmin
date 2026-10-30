<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * QA-12 菜单数据卫生：修正三处「菜单名与页面内容不符 / 同名不同物」的记录。
 *
 * BusinessSeeder 用 firstOrCreate 注册菜单——它只在 INSERT 时写属性，已部署库里的
 * 存量行不会被刷新，所以改 seeder 只对全新安装生效，线上必须靠本迁移 UPDATE。
 * （同 2026_09_09_000003_sync_menu_order.php 的处理方式。）
 *
 * 不改 name：name 是前端动态路由的 route name（router/index.js transformMenusToRoutes），
 * 改名会让已有收藏/跳转和权限标识一起失效，且治理测试只约束 name 必须英文唯一。
 */
return new class extends Migration
{
    /** name => 需要刷新的字段 */
    private const FIXES = [
        // 「收款单」指向的 /business/payment 页面实为供应商付款列表；真实收款页
        // /business/receive 一直没有菜单行，侧边栏进不去 → 合并为单一「收款管理」入口
        'finance.payment' => [
            'title' => '收款管理',
            'path' => '/business/receive',
            'component' => 'business/receive/index',
        ],
        // 分组行 path 约定指向第一个子路由，跟着 finance.payment 一起改
        'finance' => ['path' => '/business/receive'],
        // 页面标题是「供应商付款」，菜单原名「付款单」对不上
        'finance.supplier-payment' => ['title' => '供应商付款'],
        // 与出纳域「积分规则」同名不同物，改名消歧（入口保留，见下）
        'member.point-rule' => ['title' => '会员积分倍率'],
    ];

    public function up(): void
    {
        foreach (self::FIXES as $name => $attrs) {
            DB::table('auth_permission')->where('name', $name)->update($attrs + ['updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $revert = [
            'finance.payment' => ['title' => '收款单', 'path' => '/business/payment', 'component' => null],
            'finance' => ['path' => '/business/payment'],
            'finance.supplier-payment' => ['title' => '付款单'],
            'member.point-rule' => ['title' => '积分规则'],
        ];
        foreach ($revert as $name => $attrs) {
            DB::table('auth_permission')->where('name', $name)->update($attrs + ['updated_at' => now()]);
        }
    }
};
