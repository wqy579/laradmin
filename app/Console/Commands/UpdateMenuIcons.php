<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Auth\Permission;

class UpdateMenuIcons extends Command
{
    protected $signature = 'menu:icons';
    protected $description = '更新菜单图标';

    public function handle()
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
            $menu = Permission::where('name', $name)->first();
            if ($menu) {
                $menu->meta = ['icon' => $icon];
                $menu->save();
                $this->info("Updated {$name}: {$icon}");
            }
        }

        $this->info('Menu icons updated successfully');
    }
}
