<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 给「借还货管理」父菜单补图标（循环箭头 ElIconRefresh）。
 *
 * 背景：2026_10_13_000003 注册菜单时 meta 写的是空数组，侧边栏该行渲染成空
 * <el-icon> 且不报错；而 MenuIconTest 只校验 parent_id=0 的顶层菜单，借还货是
 * inventory 的子菜单，正好在盲区里。已部署库那一行已经落库，改插入语句补不回去，
 * 只能单独更新。
 *
 * 幂等 + 不覆盖人工设置：已有 icon 的行原样保留（与 System 模块
 * fill_missing_menu_icons 的口径一致）。meta 是整列 JSON，必须合并而不是覆盖，
 * 否则同列的 hidden / affix 会被抹掉。
 */
return new class extends Migration
{
    private const ICON = 'ElIconRefresh';

    public function up(): void
    {
        $row = DB::table('auth_permission')->where('name', 'borrow-return')->first();

        if (! $row) {
            return;
        }

        $meta = json_decode($row->meta ?? 'null', true) ?? [];

        if (! empty($meta['icon'])) {
            return;
        }

        $meta['icon'] = self::ICON;

        DB::table('auth_permission')
            ->where('id', $row->id)
            ->update(['meta' => json_encode($meta), 'updated_at' => now()]);
    }

    public function down(): void
    {
        $row = DB::table('auth_permission')->where('name', 'borrow-return')->first();

        if (! $row) {
            return;
        }

        $meta = json_decode($row->meta ?? 'null', true) ?? [];

        if (($meta['icon'] ?? null) !== self::ICON) {
            return;
        }

        unset($meta['icon']);

        DB::table('auth_permission')
            ->where('id', $row->id)
            ->update(['meta' => json_encode($meta), 'updated_at' => now()]);
    }
};
