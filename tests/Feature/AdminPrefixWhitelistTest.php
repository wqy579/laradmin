<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * 生产 nginx 代理白名单与本仓库路由表的同步校验。
 *
 * 线上由宝塔 nginx 承接 /admin：只有正则分支 ^/admin/(a|b|c) 里显式列出的模块前缀
 * 才 proxy_pass 到 laravels，其余全部落到 try_files ... /admin/index.html。于是
 * 「新增一个顶层前缀但没加白名单」不会 404，而是返回 200 text/html 的 517B SPA 壳，
 * 前端 axios 解析 HTML 失败 → 页面静默空白，验收方看到的是「后端零实现」。
 *
 * 同类事故已发生三次：dashboard（2026-10）、ledger（2026-10-14）、
 * perf + data（2026-10-30，QA-01 / QA-04 —— 绩效 9 页 + 基础档案 5 页被报成
 * 「后端未部署」，实际 modules/Performance 与 modules/Archive 的路由早已在 main 上）。
 *
 * 站点配置不在仓库里（宝塔托管），deploy.yml 只能在部署时幂等补插。这里补齐仓库侧
 * 能自证的两条不变量，把「改了路由忘了改部署」变成测试红灯，而不是线上哑故障。
 */
class AdminPrefixWhitelistTest extends TestCase
{
    /**
     * 已知未进 deploy.yml 白名单的顶层前缀 —— 逐条都是有意为之，新增前缀不得加进来。
     *
     * 出现本清单之外的新前缀即红灯：那意味着有人新加了顶层模块前缀却没改部署，
     * 线上会被 try_files 兜成 HTML，而 CI 与部署探针全绿。
     */
    private const KNOWN_NOT_WHITELISTED = [
        // 站点 nginx 配置成文时就已包含、不需要 deploy.yml 补插的老前缀
        'auth',
        'business',
        'system',
        // 2026-10-31 实测线上同样被 try_files 兜成 517B HTML，但归属其它机器，
        // 待各自负责人补白名单（本轮只修 QA-01 的 perf 与 QA-04 的 data）
        'agr',
        'cash',
        'f5',
        'hr',
        // 危险：nginx 正则分支没有词边界，^/admin/(asset|…) 会连带匹配
        // /admin/assets/*（前端构建产物），把静态资源一并代理进 Laravel → 后台整站白屏。
        // 要先让站点侧把分支写成 (asset)(/|$) 这类带边界的形态，才能加 asset。
        'asset',
        // 闭包路由 artisan migrate 的远程触发口，非前端调用；有意不暴露到公网
        'remote',
    ];

    /**
     * 在白名单里但故意没有 6.5 探针的前缀。
     */
    private const KNOWN_UNPROBED = [
        // nginx 分支无词边界，^/admin/(asset|…) 会连带匹配 /admin/assets/*（前端构建
        // 产物），把静态资源一并代理进 Laravel。要安全地给 asset 加探针，得先让站点
        // 侧把分支写成带边界的形态（(asset)(/|$)）。在此之前不加探针，避免误导。
        'asset',
    ];

    public function test_top_level_admin_prefixes_are_declared_in_deploy_whitelist_or_known_exception(): void
    {
        $whitelisted = $this->deployWhitelistTokens();

        $undeclared = [];
        foreach ($this->topLevelAdminPrefixes() as $prefix) {
            if (in_array($prefix, $whitelisted, true) || in_array($prefix, self::KNOWN_NOT_WHITELISTED, true)) {
                continue;
            }

            $undeclared[] = $prefix;
        }

        $this->assertSame(
            [],
            $undeclared,
            "以下顶层接口前缀既不在 deploy.yml 的 nginx 白名单补插列表，也不在已知例外清单中：\n"
            .implode("\n", $undeclared)
            ."\n线上会被 try_files 兜成 200 text/html 的 SPA index.html（页面全空、接口看起来像没实现）。"
            ."\n修法：加进 .github/workflows/deploy.yml 的 ensure_nginx_proxy token 列表，"
            .'并在 6.5 路由可达性探针里补一条该前缀下的接口路径。'
            ."\n注意 token 必须是完整前缀且不与 public/admin 下的静态目录同名或为其前缀"
            .'（如 asset 会误吞 /admin/assets/*）——nginx 分支无词边界。'
        );
    }

    /**
     * 白名单与探针必须成对：只补白名单不补探针，下次该前缀漏配照样静默上线；
     * 6.5 的注释写明了这层关系，但它管不住人只改一处。
     */
    public function test_every_nginx_whitelist_token_has_a_reachability_probe(): void
    {
        $probed = array_map(
            fn (string $path) => explode('/', $path)[0],
            $this->deployProbePaths()
        );

        $unprobed = array_values(array_diff($this->deployWhitelistTokens(), $probed, self::KNOWN_UNPROBED));

        $this->assertSame(
            [],
            $unprobed,
            "以下前缀在 ensure_nginx_proxy 白名单里，却没有 6.5 可达性探针：\n".implode("\n", $unprobed)
            ."\n在 6.5 的 for path 列表里补一条 /admin/<前缀>/<某接口>，漏配时部署才会判红。"
        );
    }

    /**
     * QA-01 / QA-04 的两个前缀必须在白名单里 —— 防止有人把这次修复改回去。
     */
    public function test_qa_fixed_prefixes_remain_whitelisted(): void
    {
        $whitelisted = $this->deployWhitelistTokens();

        foreach (['perf', 'data'] as $prefix) {
            $this->assertContains(
                $prefix,
                $whitelisted,
                "前缀 {$prefix} 已从 deploy.yml 的 nginx 白名单消失，QA-01/QA-04 的接口会退回 text/html 兜底。"
            );
        }
    }

    /**
     * 白名单 token 不得与 public/admin 下的静态目录同名或互为前缀。
     *
     * 站点 nginx 分支形如 ^/admin/(a|b)，无词边界：token 若是某静态目录名的前缀
     * （asset 之于 assets/），这些静态资源会被一并 proxy_pass 进 Laravel，后台整站白屏。
     */
    public function test_whitelist_tokens_do_not_shadow_static_assets(): void
    {
        $staticDirs = array_map(
            fn (string $path) => basename($path),
            glob(base_path('public/admin/*'), GLOB_ONLYDIR) ?: []
        );

        $shadowing = [];
        foreach ($this->deployWhitelistTokens() as $token) {
            foreach ($staticDirs as $dir) {
                if (str_starts_with($dir, $token)) {
                    $shadowing[] = "{$token} 会连带匹配静态目录 /admin/{$dir}/";
                }
            }
        }

        $this->assertSame(
            [],
            $shadowing,
            "以下 nginx 白名单 token 会误吞前端静态资源（分支无词边界）：\n".implode("\n", $shadowing)
            ."\n修法：站点侧把分支写成 ({$token})(/|$) 这类带边界的形态，或从 token 列表移除。"
        );
    }

    /**
     * @return list<string> 路由表里 /admin/{prefix}/... 的顶层前缀
     */
    private function topLevelAdminPrefixes(): array
    {
        Artisan::call('route:list', ['--json' => true]);
        $routes = json_decode((string) Artisan::output(), true);
        $this->assertIsArray($routes, 'route:list --json 解析失败');

        $prefixes = [];
        foreach ($routes as $route) {
            $segments = explode('/', (string) $route['uri']);
            if (($segments[0] ?? '') !== 'admin' || empty($segments[1])) {
                continue;
            }

            $prefixes[$segments[1]] = true;
        }

        $prefixes = array_keys($prefixes);
        sort($prefixes);

        return $prefixes;
    }

    /**
     * @return list<string> deploy.yml 中 ensure_nginx_proxy 的 for token in ... 列表
     */
    private function deployWhitelistTokens(): array
    {
        return $this->shellLoopItems('/for token in ([^;]*); do/');
    }

    /**
     * @return list<string> deploy.yml 中 6.5 探针的 for path in ... 列表
     */
    private function deployProbePaths(): array
    {
        return $this->shellLoopItems('/for path in ([^;]*); do/');
    }

    /**
     * @return list<string>
     */
    private function shellLoopItems(string $pattern): array
    {
        $source = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));
        $this->assertSame(
            1,
            preg_match($pattern, $source, $matches),
            "deploy.yml 里没解析到 {$pattern}，shell 结构可能被改动，本测试需同步更新"
        );

        return preg_split('/\s+/', trim($matches[1])) ?: [];
    }
}
