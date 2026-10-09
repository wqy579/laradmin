<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 给「换货管理」父菜单补图标（交换箭头 ElIconSort）。
 *
 * 同 BorrowReturn 的 2026_10_14_000002：注册菜单时 meta 是空数组，已部署库那一行
 * 只能靠单独更新补回；MenuIconTest 只管 parent_id=0 的顶层菜单，换货是 inventory
 * 的子菜单，不在它的校验范围里。
 *
 * 幂等 + 不覆盖人工设置 + 合并 meta（保住同列 hidden / affix）。
 */
return new class extends Migration
{
    private const ICON = 'ElIconSort';

    public function up(): void
    {
        $row = DB::table('auth_permission')->where('name', 'exchange')->first();

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
        $row = DB::table('auth_permission')->where('name', 'exchange')->first();

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
