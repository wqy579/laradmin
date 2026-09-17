<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $icons = [
            'price' => 'ElIconMoney',
            'inventory' => 'ElIconBox',
            'order' => 'ElIconDocument',
            'finance' => 'ElIconWallet',
            'report' => 'ElIconDataBoard',
            'office' => 'ElIconMemo',
            'visit' => 'ElIconLocation',
        ];

        foreach ($icons as $name => $icon) {
            DB::table('auth_permission')
                ->where('name', $name)
                ->where('type', 'menu')
                ->update([
                    'meta' => json_encode(['icon' => $icon]),
                ]);
        }
    }

    public function down(): void
    {
        DB::table('auth_permission')
            ->where('type', 'menu')
            ->whereNotNull('meta')
            ->update([
                'meta' => null,
            ]);
    }
};
