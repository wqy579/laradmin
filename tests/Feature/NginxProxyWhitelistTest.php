<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * 部署 nginx 代理白名单基线（QA-03）。
 *
 * 线上宝塔配置对 /admin 走「正则代理 + try_files 兜底」：只有列进正则分组的顶层前缀
 * 才 proxy_pass 到 Laravel，其余一律兜成 SPA index.html（200 text/html）。
 * dashboard（2026-10）、ledger（2026-10-14）、hr/agr/f5（QA-03，9 个页面全空）
 * 三次事故同一根因：模块加了新顶层前缀，没人记得回来补 nginx。
 *
 * deploy.yml 的 ensure_nginx_proxy 已有幂等自愈，但「该列哪些前缀」仍是人肉清单，
 * 漏一个 token 与没写自愈没有区别。这里把清单钉到唯一事实源上：
 *  1) token 列表必须覆盖前端 API 层调用的全部顶层前缀（用户可达 = 必须代理）；
 *  2) 第 6.5 步探测的每条路径必须在路由表真实存在（探测本身不许空转）；
 *  3) 探测路径的前缀必须在 token 列表里（探测覆盖 = 自愈覆盖）。
 *
 * 新增顶层前缀的模块：前端 api 文件写 request.get('新前缀/...') 后，
 * 把「新前缀」加进 deploy.yml 的 token 列表与 6.5 探测即可，本测试会一直提醒到加为止。
 */
class NginxProxyWhitelistTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/deploy.yml';

    /**
     * 例外：不要求进 nginx 白名单的顶层前缀。
     * remote 是部署脚本自用的内核闭包路由（走 127.0.0.1 内部调用，不经宝塔站点入口）。
     */
    private const PROXY_EXEMPT = ['remote'];

    public function test_deploy_whitelist_tokens_cover_every_frontend_prefix(): void
    {
        $prefixes = $this->whitelistPrefixes();
        $this->assertNotEmpty($this->whitelistTokens(), 'deploy.yml 里找不到 ensure_nginx_proxy 的 token 列表，正则或脚本结构变了');

        $needed = array_diff($this->frontendPrefixes(), self::PROXY_EXEMPT);
        sort($needed);

        $missing = array_values(array_diff($needed, $prefixes));
        $this->assertSame(
            [],
            $missing,
            "以下顶层前缀前端在调用、但不在 deploy.yml nginx 白名单 token 列表里，线上会被 try_files 兜成 SPA HTML（QA-03 同型事故）：\n"
            .implode("\n", $missing)
        );
    }

    public function test_deploy_route_probe_paths_exist_and_are_whitelisted(): void
    {
        $prefixes = $this->whitelistPrefixes();
        $probePaths = $this->probePaths();
        $this->assertNotEmpty($probePaths, 'deploy.yml 里找不到 6.5 步探测路径列表');

        $registered = [];
        Artisan::call('route:list', ['--json' => true]);
        foreach (json_decode((string) Artisan::output(), true) as $route) {
            $registered[] = $route['uri'];
        }

        $unknown = [];
        $unwhitelisted = [];
        foreach ($probePaths as $path) {
            if (! in_array('admin/'.$path, $registered, true)) {
                $unknown[] = "admin/{$path}";
            }
            $prefix = explode('/', $path)[0];
            if (! in_array($prefix, $prefixes, true)) {
                $unwhitelisted[] = "{$path}（前缀 {$prefix} 不在 token 列表）";
            }
        }

        $this->assertSame([], $unknown, "6.5 步探测的路由在路由表不存在，探测永远红（或已被改名）：\n".implode("\n", $unknown));
        $this->assertSame([], $unwhitelisted, "6.5 步探测的前缀没进自愈 token 列表，探测失败时无从自愈：\n".implode("\n", $unwhitelisted));
    }

    /** deploy.yml ensure_nginx_proxy 的 `for token in a b c; do` 列表 */
    private function whitelistTokens(): array
    {
        $source = (string) file_get_contents(base_path(self::WORKFLOW));

        if (! preg_match('/for token in ([^;]+); do/', $source, $m)) {
            return [];
        }

        return preg_split('/\s+/', trim($m[1])) ?: [];
    }

    /**
     * 同一份 token 列表，剥掉 nginx 边界后缀（`asset/` → `asset`）。
     *
     * 前端前缀与探针首段都是不带边界的裸前缀，拿原样 token 做 diff 会误报「asset 没进
     * 白名单」。带边界的 `asset/` 本身必须保留（裸 asset 会误吞 /admin/assets/*），
     * 故归一化放在取数这一层，与 AdminPrefixWhitelistTest 同一处理由。
     *
     * @return list<string>
     */
    private function whitelistPrefixes(): array
    {
        return array_values(array_unique(array_map(
            fn (string $token) => rtrim($token, '/'),
            $this->whitelistTokens()
        )));
    }

    /** deploy.yml 6.5 步的 `for path in a b c; do` 列表 */
    private function probePaths(): array
    {
        $source = (string) file_get_contents(base_path(self::WORKFLOW));

        if (! preg_match('/for path in ([^;]+); do/', $source, $m)) {
            return [];
        }

        return preg_split('/\s+/', trim($m[1])) ?: [];
    }

    /**
     * 前端 API 层调用的全部顶层前缀（与 RouteBaselineTest 的解析器同源规则）。
     */
    private function frontendPrefixes(): array
    {
        $prefixes = [];
        foreach (glob(base_path('frontend/src/api/*.js')) ?: [] as $file) {
            $source = (string) file_get_contents($file);

            if (! preg_match_all(
                '/request\.(get|post|put|del|delete|patch)\(\s*([\'"`])((?:[^\'"`\\\\]|\\\\.)*?)\2/',
                $source,
                $matches,
                PREG_SET_ORDER
            )) {
                continue;
            }

            foreach ($matches as $match) {
                $uri = trim($match[3], "/ \t\n");
                if ($uri === '' || str_starts_with($uri, 'http')) {
                    continue;
                }
                $prefixes[strtolower(explode('/', $uri)[0])] = true;
            }
        }
        unset($prefixes['']);

        return array_keys($prefixes);
    }
}
