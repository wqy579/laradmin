<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 补回丢失的菜单图标。
     *
     * 症状：侧边栏「首页」前面没有图标。
     *
     * 起因有两层，各占一半：
     * ① 前端 boot.js 过去只注册模板里静态引用到的 66 个 Element Plus 图标，
     *    而菜单图标存在 auth_permission.meta.icon 里、运行时才拿到名字，
     *    数据库写了白名单外的名字就渲染成空 <el-icon>，且不报任何错。
     *    前端已改为全量注册，这一层已修。
     * ② 2026_02_26_000001_update_menu_structure 建 home / miniapp 两行时根本没写 meta
     *    （列值为 NULL），而 2026_09_04_000001_update_menu_icons 只补了
     *    price / inventory / order / finance / report / office / visit 七项，
     *    从来没有碰过 home 和 miniapp。所以线上这两行至今没有图标——
     *    前端注册多少都救不回来，只能补数据。
     *
     * 这里对 meta 做合并而不是整体覆盖。前一个 update_menu_icons 迁移直接
     * meta = ['icon' => ...]，会把同列里的 hidden / affix / hiddenBreadcrumb
     * 一并抹掉。
     *
     * 幂等：已有图标的行一律跳过，不覆盖人工调整过的值。
     */
    public function up(): void
    {
        $icons = [
            // 首页：ElIconHomeFilled
            'home' => 'ElIconHomeFilled',
            // 小程序管理：与 frontend/src/config/menuIcons.js 的回退映射保持一致
            'miniapp' => 'ElIconPlatform',
        ];

        foreach ($this->menusMissingIcon($icons) as $menu) {
            $meta = $this->decodeMeta($menu->meta);

            $meta['icon'] = $icons[$menu->name];

            $this->updateMenu($menu->id, $meta);
        }
    }

    public function down(): void
    {
        $icons = [
            'home' => 'ElIconHomeFilled',
            'miniapp' => 'ElIconPlatform',
        ];

        foreach ($this->menusMissingIcon($icons) as $menu) {
            $meta = $this->decodeMeta($menu->meta);
            if (($meta['icon'] ?? null) !== $icons[$menu->name]) {
                // 不是本迁移写入的值（人工改过或本就不匹配），不动
                continue;
            }

            unset($meta['icon']);

            $this->updateMenu($menu->id, $meta);
        }
    }

    /**
     * 取出自定图标映射中、当前 meta 里没有图标的菜单行
     */
    private function menusMissingIcon(array $icons)
    {
        return DB::table('auth_permission')
            ->where('type', 'menu')
            ->whereIn('name', array_keys($icons))
            ->get()
            ->filter(function ($menu) {
                return empty($this->decodeMeta($menu->meta)['icon']);
            })
            ->values();
    }

    /**
     * meta 列可空且历史上被整体覆盖过，解码必须容忍 NULL 和非法 JSON
     */
    private function decodeMeta(?string $meta): array
    {
        if ($meta === null) {
            return [];
        }

        $decoded = json_decode($meta, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function updateMenu(int $id, array $meta): void
    {
        DB::table('auth_permission')
            ->where('id', $id)
            ->update([
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }
};
