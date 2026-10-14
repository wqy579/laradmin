<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Ledger\Seeders\LedgerSubjectSeeder;

/**
 * 已部署环境补丁（生产只跑 migrate、不跑 db:seed，000006 对已存在行只读 id 不改 meta）：
 *
 *  1) 给 ledger 菜单树补 meta.hidden=false / hiddenBreadcrumb=false
 *     —— 2026-10 验收缺陷②：000006/LedgerSeeder 的三元在「有 icon」分支只写 icon，
 *     顶级「总账管理」meta 里没有 hidden:false，菜单被前端过滤掉。
 *     用 += 合并：已有键（含人工设过的 hidden:true）不覆盖，只补缺失键。
 *
 *  2) 预置《小企业会计准则》科目 —— 生产 migrate-only 下 ledger_subjects 为空，
 *     会计科目/凭证/报表页全部空表。LedgerSubjectSeeder 按 code 幂等，重复跑安全。
 */
return new class extends Migration
{
    public function up(): void
    {
        $names = [
            'ledger', 'ledger.subject', 'ledger.opening', 'ledger.voucher', 'ledger.voucher-query',
            'ledger.voucher-audit', 'ledger.period', 'ledger.balance', 'ledger.report',
        ];

        foreach ($names as $name) {
            $row = DB::table('auth_permission')->where('name', $name)->where('type', 'menu')->first();
            if (! $row) {
                continue;
            }
            $meta = json_decode($row->meta ?? 'null', true) ?: [];
            // += 只补缺失键，不动已有键（与 System 000002 fill_missing_menu_icons 同约定）
            $meta += ['hidden' => false, 'hiddenBreadcrumb' => false];
            $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE);
            if ($encoded !== $row->meta) {
                DB::table('auth_permission')->where('id', $row->id)->update([
                    'meta' => $encoded,
                    'updated_at' => now(),
                ]);
            }
        }

        (new LedgerSubjectSeeder)->run();
    }

    public function down(): void
    {
        // 不回滚：菜单 meta 补键与预置科目都是幂等的「补数据」，down 保持 no-op
    }
};
