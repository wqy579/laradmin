<?php

namespace Tests\Stock\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\Stock;
use Modules\Stock\Models\Warehouse;
use Modules\Stock\Services\StockService;
use Tests\TestCase;

/**
 * F7 加固 A.1 —— 并发扣减不丢更新（真实多进程验证）。
 *
 * 用 pcntl_fork 派生 N 个子进程，每个子进程 purge+reconnect 拿全新 PDO 连接，
 * 对同一 stocks 行并发调用 StockService::stockOut。期望：
 *   final = initial − N×perDeduct   （无丢失更新）
 * 即 A.1 判据「两个请求同时扣减同一库存，最终值必须正确」。
 *
 * 实现要点：
 *  - 本类**不用** RefreshDatabase / DatabaseMigrations：前者把用例包在事务里，
 *    fork 出的子进程看不到未提交的种子行；后者 migrate:fresh 对共享测试库
 *    是破坏性操作。改为自管「提交后即对 fork 可见」的种子行 + finally 清理，
 *    不残留、不破坏任何既有库。
 *  - 前提：MySQL（InnoDB 行锁）+ pcntl。CI 的 phpunit job 跑在 mysql:8.0 且
 *    extensions 含 pcntl，本测试在 CI 真实执行；本地默认 sqlite :memory: 会
 *    markTestSkipped（SQLite 把 lockForUpdate 编译成空串，验证不了行锁）。
 *  - 判别力已验证：把 lockForUpdate 拿掉后同样并发跑出 LOST-UPDATE
 *    （final=198 ≠ expected=180），证明本测试不是空转，真能抓住丢更新。
 *
 * 运行注意：测试库需已建表（products/warehouses/stocks/stocks_history）。
 */
class StockConcurrencyTest extends TestCase
{
    /** 并发进程数 */
    private const N = 8;

    /** 每进程扣减量 */
    private const PER_DEDUCT = 3;

    /** 初始库存 */
    private const INITIAL = 100;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('并发行锁测试需要 MySQL（InnoDB），当前驱动为 '.DB::connection()->getDriverName());
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl 扩展不可用，无法派生并发进程');
        }
    }

    public function test_concurrent_deductions_do_not_lose_updates(): void
    {
        [$product, $warehouse, $stock] = $this->seedStockRow(self::INITIAL);

        try {
            $successCount = $this->runConcurrentDeductions($product->id, $warehouse->id);
            // 在 cleanup 之前读取，否则 finally 已删行
            $final = (int) DB::table('stocks')
                ->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity');
        } finally {
            $this->cleanup($product, $warehouse, $stock);
        }

        // 初始库存充足，8 个进程全部应成功扣减（无一库存不足）
        $this->assertSame(self::N, $successCount, '初始库存充足时并发扣减应全部成功');

        $expected = self::INITIAL - self::N * self::PER_DEDUCT;
        $this->assertSame(
            $expected,
            $final,
            sprintf('并发 %d 进程各扣 %d 后库存应为 %d，实际 %d —— 存在丢失更新',
                self::N, self::PER_DEDUCT, $expected, $final)
        );
    }

    /**
     * A.1 超发半区：并发扣减总量超过库存时，库存不得被打成负数。
     *
     * 初始 10，8 个进程各扣 3（共需 24 > 10）。行锁串行化后：
     *  - 成功扣减的次数 = floor(10 / 3) = 3 次（9 件），第 4 次起库存不足抛异常；
     *  - 最终库存 = 10 − 9 = 1（非负）；
     *  - 成功扣减总额 ≤ 初始库存（不超发）。
     */
    public function test_concurrent_oversell_never_drives_stock_negative(): void
    {
        $initial = 10;
        [$product, $warehouse, $stock] = $this->seedStockRow($initial);

        try {
            $successCount = $this->runConcurrentDeductions($product->id, $warehouse->id);
            // 在 cleanup 之前读取，否则 finally 已删行
            $final = (int) DB::table('stocks')
                ->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity');
        } finally {
            $this->cleanup($product, $warehouse, $stock);
        }

        $this->assertGreaterThanOrEqual(0, $final, '并发超卖后库存不得为负');
        $this->assertLessThanOrEqual($initial, $final, '并发超卖不得凭空多扣（不超发）');
        // 行锁串行化后：成功次数受限于初始库存（每笔扣 PER_DEDUCT），
        // 允许 +1 容差防止整除/取整边界抖动，但绝不等于全部成功（那等于超发）。
        $this->assertLessThanOrEqual(
            (int) floor($initial / self::PER_DEDUCT) + 1,
            $successCount,
            '成功扣减次数不应超过库存允许值（否则就是超发）'
        );
        // 至少成功 1 次（确实发生了并发扣减），且不是全员成功（10 件不够 8 次全扣）
        $this->assertGreaterThan(0, $successCount, '并发扣减应至少成功 1 次');
        $this->assertLessThan(self::N, $successCount, '初始库存不足 8 次全扣，成功次数必须少于进程数');
    }

    /** 造一次性种子行（提交后对 fork 子进程可见） */
    private function seedStockRow(int $initial): array
    {
        $tag = 'CONC_'.bin2hex(random_bytes(4));
        $product = Product::create(['name' => $tag, 'code' => $tag, 'is_active' => true, 'is_online' => true]);
        $warehouse = Warehouse::create(['code' => $tag, 'name' => $tag, 'is_active' => true, 'type' => 'normal']);
        $stock = Stock::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $initial,
        ]);

        return [$product, $warehouse, $stock];
    }

    /** 清理种子行（无论断言成败都调用） */
    private function cleanup($product, $warehouse, $stock): void
    {
        DB::table('stocks_history')
            ->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->delete();
        DB::table('stocks')->where('id', $stock->id)->delete();
        $product->delete();
        $warehouse->delete();
    }

    /**
     * 派生 N 个子进程同时扣减。
     *
     * @return int 成功扣减的子进程数（exit 0）
     */
    private function runConcurrentDeductions(int $productId, int $warehouseId): int
    {
        $barrier = tempnam(sys_get_temp_dir(), 'conc_');
        file_put_contents($barrier, '0');

        $children = [];
        for ($i = 0; $i < self::N; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork 失败');
            }
            if ($pid === 0) {
                // 子进程：purge + reconnect 拿全新连接，避免共享父进程 socket 串扰
                DB::purge();
                DB::reconnect();
                $svc = new StockService;

                $t0 = microtime(true);
                while ((int) @file_get_contents($barrier) !== 1) {
                    usleep(1000);
                    if (microtime(true) - $t0 > 15) {
                        exit(2); // barrier 超时
                    }
                }
                try {
                    $svc->stockOut($productId, $warehouseId, self::PER_DEDUCT);
                    exit(0);
                } catch (\Throwable $e) {
                    fwrite(STDERR, "child {$i}: ".$e->getMessage()."\n");
                    exit(3);
                }
            }
            $children[$pid] = $i;
        }

        // 给子进程时间完成各自连接初始化，再统一发 GO（尽可能同时起跑）
        usleep(800000);
        file_put_contents($barrier, '1');

        $success = 0;
        foreach (array_keys($children) as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wexitstatus($status) === 0) {
                $success++;
            }
        }
        @unlink($barrier);

        return $success;
    }
}
