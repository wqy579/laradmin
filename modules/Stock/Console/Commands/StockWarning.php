<?php

namespace Modules\Stock\Console\Commands;

use App\Support\Inbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 库存预警定时任务：查询低于安全库存的商品，汇总后通知库管与采购。
 *
 * 排程：每天早上 8 点跑一次（见 routes/console.php 的 Schedule 与 laravel-s
 * ScheduledTimerJob 两条路径，二选一生效）。
 *
 * 预警口径：product.stock_qty < product.safety_stock 且 safety_stock > 0。
 * safety_stock=0 视为「不设预警线」，否则每个未配置安全库存的商品都会被误报。
 * 汇总成一条消息发给每个收件人（不是一商品一条），否则上百个 SKU 会把铃铛刷爆。
 *
 * 幂等：同一用户同一天只发一条（消息标题带日期前缀 + 查当天已发标记），
 * 避免 8 点调度叠加 laravel-s 多进程时重复推送。
 */
class StockWarning extends Command
{
    protected $signature = 'stock:warning {--date= : 指定日期(YYYY-MM-DD)，默认今天，便于手动补发/测试}';

    protected $description = '库存预警：查询低于安全库存的商品，汇总通知库管与采购（每日 8 点）';

    // 库管 + 采购岗位：按权限码反查（本系统无独立岗位字段）
    private const TARGET_PERMISSIONS = ['inventory.stock-check', 'inventory.purchase'];

    public function handle(): int
    {
        // 注意是 option 不是 argument：--date 是带横线的可选项
        $date = $this->option('date') ?: now()->toDateString();

        // 列不存在时静默退出：迁移未跑的环境不应让定时任务报错
        if (! Schema::hasColumn('products', 'safety_stock') || ! Schema::hasColumn('products', 'stock_qty')) {
            $this->warn('products 缺少 safety_stock/stock_qty 列，跳过库存预警');

            return self::SUCCESS;
        }

        $warnings = DB::table('products')
            ->where('is_active', 1)
            ->where('safety_stock', '>', 0)
            ->whereRaw('stock_qty < safety_stock')
            ->select('id', 'name', 'code', 'stock_qty', 'safety_stock')
            ->orderBy('name')
            ->limit(200)
            ->get();

        if ($warnings->isEmpty()) {
            $this->info('无库存预警商品');

            return self::SUCCESS;
        }

        $userIds = Inbox::userIdsByPermissions(self::TARGET_PERMISSIONS);
        if (empty($userIds)) {
            $this->warn('未找到库管/采购用户（无相关权限），跳过通知');

            return self::SUCCESS;
        }

        // 去掉当天已收到过预警的用户，避免重复推送
        $alreadyNotified = DB::table('messages')
            ->where('type', 'stock_warning')
            ->whereDate('created_at', $date)
            ->pluck('user_id')
            ->unique()
            ->all();
        $userIds = array_values(array_diff($userIds, $alreadyNotified));

        if (empty($userIds)) {
            $this->info('当日预警已通知过，跳过');

            return self::SUCCESS;
        }

        $title = "[{$date}] 库存预警：{$warnings->count()} 个商品低于安全库存";
        $content = $this->buildSummary($warnings);

        $written = Inbox::sendToUsers(
            $userIds,
            $title,
            $content,
            Inbox::TYPE_STOCK_WARNING,
            'inventory',
            [
                'entity_type' => 'stock_warning',
                'entity_id' => null,
                'path' => '/business/stock',
            ]
        );

        $this->info("库存预警已发送：{$warnings->count()} 个商品 → ".count($userIds).' 人（写入 '.$written.' 条消息）');

        return self::SUCCESS;
    }

    /** 生成可读的预警摘要：前 15 个商品 + 总数 */
    private function buildSummary($warnings): string
    {
        $lines = collect($warnings)->take(15)->map(function ($row) {
            $gap = (float) $row->safety_stock - (float) $row->stock_qty;

            return sprintf('· %s（%s）当前 %s / 安全线 %s，缺 %.0f',
                $row->name,
                $row->code ?? '-',
                $row->stock_qty,
                $row->safety_stock,
                $gap,
            );
        });

        $summary = '以下商品库存低于安全库存，请安排补货：'.PHP_EOL.$lines->implode(PHP_EOL);

        if ($warnings->count() > 15) {
            $summary .= PHP_EOL.'…（共 '.$warnings->count().' 个，仅显示前 15 个）';
        }

        return $summary;
    }
}
