<?php

namespace Modules\Business\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 用联凯 products_all.json 的 cb_jg（成本价）同步到 products.cost_price。
 *
 * 匹配键：products.external_id = 联凯 cpid（02_backfill_external_id.sql 已回填）。
 * 仅更新「不一致」的行：当前 cost_price 与目标值（cb_jg 四舍五入到 2 位，与列
 * decimal(10,2) 一致）不同的才写；已一致的行不动，幂等可重跑。
 *
 * 注：成本价目标列 cost_price 为 decimal(10,2)，cb_jg 原 8 位小数会被四舍五入
 * 到 2 位（分）。如需保留全精度，先 ALTER TABLE products MODIFY cost_price
 * DECIMAL(12,4) 再把本命令里 round(...,2) 去掉。
 *
 * 数据文件：modules/Business/data/cost_price_sync.json（{cpid: cb_jg}）
 */
class SyncCostPrices extends Command
{
    protected $signature = 'business:sync-cost-prices {--dry-run : 只核对不一致的数量，不写入}';

    protected $description = '用联凯 cb_jg 同步 products.cost_price（按 external_id 匹配，仅更新不一致的行）';

    private const DATA_FILE = __DIR__.'/../../data/cost_price_sync.json';

    public function handle(): int
    {
        if (! is_file(self::DATA_FILE)) {
            $this->error('数据文件不存在: '.self::DATA_FILE);

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents(self::DATA_FILE), true);
        if (! is_array($data)) {
            $this->error('数据文件解析失败');

            return self::FAILURE;
        }

        $cpids = array_keys($data);
        $this->info('数据条目: '.count($cpids));

        // 分批取现有 cost_price，避免一次 IN 5935 条
        $map = [];
        foreach (array_chunk($cpids, 1000) as $chunk) {
            $rows = DB::table('products')
                ->whereIn('external_id', $chunk)
                ->pluck('cost_price', 'external_id');
            foreach ($rows as $eid => $cp) {
                $map[$eid] = $cp === null ? null : (float) $cp;
            }
        }

        $found = 0;
        $notFound = 0;
        $matched = 0;
        $mismatched = 0;
        $toUpdate = [];
        foreach ($data as $cpid => $cb) {
            if (! array_key_exists($cpid, $map)) {
                $notFound++;

                continue;
            }
            $found++;
            $target = round((float) $cb, 2);
            $current = $map[$cpid] === null ? null : round($map[$cpid], 2);
            if ($current !== null && abs($current - $target) < 0.005) {
                $matched++;
            } else {
                $mismatched++;
                $toUpdate[] = ['cpid' => $cpid, 'target' => $target];
            }
        }

        $this->info('匹配到商品(external_id): '.$found);
        $this->info('未匹配(external_id 不在库): '.$notFound);
        $this->info('已一致: '.$matched);
        $this->info('不一致: '.$mismatched);

        if ($this->option('dry-run')) {
            $this->info('【dry-run】未写入。去掉 --dry-run 执行替换。');

            return self::SUCCESS;
        }

        if (empty($toUpdate)) {
            $this->info('无不一致，无需更新。');

            return self::SUCCESS;
        }

        $updated = 0;
        DB::transaction(function () use ($toUpdate, &$updated) {
            foreach ($toUpdate as $r) {
                $updated += DB::table('products')
                    ->where('external_id', $r['cpid'])
                    ->update(['cost_price' => $r['target'], 'updated_at' => now()]);
            }
        });

        $this->info('已替换(更新行数): '.$updated);

        return self::SUCCESS;
    }
}
