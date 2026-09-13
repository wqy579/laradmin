<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * T1 路由基线快照
 *
 * 两道防线：
 *  1) 路由表整体与黄金文件比对 —— 任何路由的新增/删除/中间件变化都会被察觉；
 *  2) 校验每条 class@action 路由的控制器类与方法真实存在 —— 类名写错、类不存在、
 *     方法不存在（调用时 500）都会在这里炸出来（当初 Cms 模块 fatal 就是这类问题）。
 *
 * 重新生成黄金文件：UPDATE_SNAPSHOTS=1 php vendor/bin/phpunit --filter RouteBaselineTest
 */
class RouteBaselineTest extends TestCase
{
    public function test_route_table_matches_baseline_snapshot(): void
    {
        $current = $this->currentRoutes();
        $snapshotPath = __DIR__.'/../snapshots/routes.json';

        if (getenv('UPDATE_SNAPSHOTS')) {
            if (! is_dir(dirname($snapshotPath))) {
                mkdir(dirname($snapshotPath), 0777, true);
            }
            file_put_contents($snapshotPath, $this->encodeSnapshot($current));
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertFileExists(
            $snapshotPath,
            '路由基线快照不存在，请先运行：UPDATE_SNAPSHOTS=1 php vendor/bin/phpunit --filter RouteBaselineTest'
        );

        $expected = json_decode((string) file_get_contents($snapshotPath), true);
        $this->assertIsArray($expected, '路由基线快照文件损坏（不是合法 JSON 数组）');
        $this->assertSame($expected, $current, $this->buildDiffMessage($expected, $current));
    }

    public function test_every_class_based_route_points_to_existing_method(): void
    {
        Artisan::call('route:list', ['--json' => true]);
        $routes = json_decode(Artisan::output(), true);

        $broken = [];
        foreach ($routes as $route) {
            $action = $route['action'];
            if (! is_string($action) || $action === 'Closure' || ! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            if (! class_exists($class)) {
                $broken[] = sprintf('%-8s %-45s %s（控制器类不存在）', $route['method'], $route['uri'], $action);

                continue;
            }

            if (! method_exists($class, $method)) {
                $broken[] = sprintf('%-8s %-45s %s（控制器方法不存在，调用必然 500）', $route['method'], $route['uri'], $action);
            }
        }

        $this->assertSame(
            [],
            $broken,
            "以下路由指向不存在的控制器类/方法：\n".implode("\n", $broken)
        );
    }

    /**
     * 拉取当前路由表并做归一化（去掉 HEAD 别名、空 middleware 转数组、确定性排序）
     */
    private function currentRoutes(): array
    {
        Artisan::call('route:list', ['--json' => true]);
        $routes = json_decode(Artisan::output(), true);
        $this->assertIsArray($routes, 'route:list --json 输出无法解析');
        $this->assertNotEmpty($routes, '路由表为空，路由注册可能整体失败');

        $normalized = [];
        foreach ($routes as $route) {
            $methods = array_values(array_filter(
                explode('|', (string) $route['method']),
                fn (string $m) => $m !== 'HEAD'
            ));

            $normalized[] = [
                'method' => implode('|', $methods),
                'uri' => (string) $route['uri'],
                'name' => $route['name'],
                'action' => (string) $route['action'],
                'middleware' => $route['middleware'] ?: [],
            ];
        }

        usort($normalized, fn (array $a, array $b) => [$a['uri'], $a['method']] <=> [$b['uri'], $b['method']]);

        return $normalized;
    }

    private function encodeSnapshot(array $routes): string
    {
        return json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    private function buildDiffMessage(array $expected, array $current): string
    {
        $expectedRows = array_map('json_encode', $expected);
        $currentRows = array_map('json_encode', $current);

        $added = array_diff($currentRows, $expectedRows);
        $removed = array_diff($expectedRows, $currentRows);

        $lines = [
            sprintf('路由表与基线快照不一致：新增 %d 条，移除 %d 条。', count($added), count($removed)),
            '如果这是有意变更，请运行：UPDATE_SNAPSHOTS=1 php vendor/bin/phpunit --filter RouteBaselineTest 更新黄金文件。',
            '',
        ];
        foreach ($added as $row) {
            $lines[] = '  + '.$row;
        }
        foreach ($removed as $row) {
            $lines[] = '  - '.$row;
        }

        return implode("\n", $lines);
    }
}
