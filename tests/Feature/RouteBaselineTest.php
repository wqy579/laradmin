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
     * 前后端契约：前端 API 层声明过的接口，后端必须真的存在，而且必须要求管理员鉴权。
     *
     * frontend/src/api/*.js 是「调用方契约」，后端路由表是实现。两边不一致时
     * 不会有任何报错，只有用户点按钮才 404：通知模块 11 个接口、入库/出库的
     * detail/edit/delete/approve、权限详情、用户导出（前端 POST、后端只声明 GET）
     * 全是这样静默坏的。
     *
     * 这里直接解析前端源码而不是维护一份手写名单——接口增删会自动跟着变，
     * 不需要人记得回来同步；快照比对只能发现「路由表变了」，发现不了
     * 「本该存在却从来没有」。
     *
     * 同时断言 auth.check:admin：通知内容、用户导出都属于需要鉴权的数据，
     * 一旦有人把路由加到 auth 组外面就是越权访问，而快照比对只会把它当成一次
     * 「预期内的新增」放过。
     */
    public function test_endpoints_declared_by_frontend_api_layer_exist_and_require_auth(): void
    {
        $routes = $this->currentRoutes();

        $registered = [];
        foreach ($routes as $route) {
            $registered[$route['method'].'|'.$this->normalizeUri($route['uri'])] = $route['middleware'];
        }

        $declared = $this->frontendDeclaredEndpoints();
        $this->assertNotEmpty($declared, '没解析到任何前端 API 声明，解析器可能失效了');

        // 登录是匿名入口，走单独的 rate.limit:login；其余一律要 admin 鉴权
        $anonymousAllowed = ['POST|admin/auth/login'];

        $missing = [];
        $unauthenticated = [];

        foreach ($declared as [$method, $uri, $origin]) {
            $key = $method.'|'.$uri;

            if (!isset($registered[$key])) {
                $missing[] = "$method $uri（来自 $origin）";

                continue;
            }

            if (!in_array('auth.check:admin', $registered[$key], true) && !in_array($key, $anonymousAllowed, true)) {
                $unauthenticated[] = "$method $uri（缺少 auth.check:admin，来自 $origin）";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "以下前端已声明的接口在后端没有路由，调用必然 404：\n".implode("\n", $missing)
        );

        $this->assertSame(
            [],
            $unauthenticated,
            "以下前端已声明的接口未要求管理员鉴权，存在越权访问风险：\n".implode("\n", $unauthenticated)
        );
    }

    /**
     * 解析 frontend/src/api/*.js 里的 request.<method>(<path>) 声明。
     *
     * 返回 [[METHOD, 'admin/<uri>', '相对文件名'], ...]。模板字符串的 ${...} 段
     * 归一化成 {param}（前端统一写 ${id}，而后端路由参数名各不相同，比对前必须归一）。
     * 路径前缀与 frontend/src/config/index.js 的 API_URL（以 /admin/ 结尾）一致。
     */
    private function frontendDeclaredEndpoints(): array
    {
        $verbMap = [
            'get' => 'GET', 'post' => 'POST', 'put' => 'PUT',
            'delete' => 'DELETE', 'del' => 'DELETE', 'patch' => 'PATCH',
        ];

        $declared = [];
        foreach (glob(base_path('frontend/src/api/*.js')) ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $origin = str_replace(base_path().'/', '', $file);

            if (!preg_match_all(
                '/request\.(get|post|put|del|delete|patch)\(\s*([\'"`])((?:[^\'"`\\\\]|\\\\.)*?)\2/',
                $source,
                $matches,
                PREG_SET_ORDER
            )) {
                continue;
            }

            foreach ($matches as $match) {
                $uri = preg_replace('/\$\{[^}]*\}/', '{param}', $match[3]);
                $uri = str_replace('\\', '', trim($uri, "/ \t\n"));

                if ($uri === '') {
                    continue;
                }

                $declared[] = [$verbMap[$match[1]], 'admin/'.$uri, $origin];
            }
        }

        $unique = [];
        foreach ($declared as $item) {
            $unique[$item[0].'|'.$item[1]] = $item;
        }
        ksort($unique);

        return array_values($unique);
    }

    private function normalizeUri(string $uri): string
    {
        return preg_replace('/\{[^}]*\}/', '{param}', $uri);
    }

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
