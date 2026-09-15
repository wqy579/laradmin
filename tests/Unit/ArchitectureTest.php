<?php

namespace Tests\Unit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * 架构守卫：用静态文件扫描断言模块边界，防止依赖方向退化。
 *
 * 纯扫描、不连数据库，秒级完成，适合每次提交都跑。
 * 目标形态见 docs/MODULARIZATION.md：Auth 为底层身份模块，
 * Business / System 单向依赖 Auth，模块间无环。
 */
class ArchitectureTest extends TestCase
{
    /**
     * 模块间允许的依赖边（自底向上，key 形如 "System->Auth"）
     *
     * Auth 是底层身份模块，不出边；Business / System 只准指向 Auth。
     * 名单里只有指向 Auth 的单向边，结构上不可能成环。
     *
     * 名单是动态发现模块后按边校验的：新增模块若不带任何模块依赖则直接通过，
     * 一旦引用了别的模块就必须显式登记这条边并写明理由，不会静默放行。
     */
    private const ALLOWED_MODULE_EDGES = [
        'Business->Auth',
        'System->Auth',
    ];
    /**
     * 扫描目录下所有 PHP 文件，返回引用了 Modules\<module>\ 的文件路径
     *
     * 先剥掉注释再匹配：契约与文档注释里会提到模块命名空间来解释设计，
     * 那不是真实依赖。用 tokenizer 而不是正则，避免被字符串字面量误判。
     */
    private function refsTo(string $dir, string $module): array
    {
        $needle = 'Modules\\'.$module.'\\';
        $hits = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = $this->stripComments((string) file_get_contents($file->getPathname()));

            if (str_contains($content, $needle)) {
                $hits[] = $file->getPath();
            }
        }

        return array_values(array_unique($hits));
    }

    /** 剥掉单行注释与文档注释，保留字符串字面量 */
    private function stripComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * 模块 → 模块的依赖边：key 形如 "System->Auth"，value 为引用源文件路径
     *
     * 模块清单由 glob 动态发现，因此新增模块时本用例会自动覆盖它，
     * 而不用同步改这份测试。
     */
    private function moduleEdges(): array
    {
        $modules = array_values(array_map(
            'basename',
            glob(base_path('modules/*'), GLOB_ONLYDIR) ?: []
        ));
        $edges = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('modules'), RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace(base_path().'/', '', $file->getPath());
            $content = $this->stripComments((string) file_get_contents($file->getPathname()));

            foreach ($modules as $from) {
                if (!str_starts_with($path, "modules/$from/")) {
                    continue;
                }

                foreach ($modules as $to) {
                    if ($from === $to || !str_contains($content, "Modules\\$to\\")) {
                        continue;
                    }

                    $edges["$from->$to"][] = $path;
                }
            }
        }

        return $edges;
    }

    /**
     * Auth 是底层身份模块，不得反向依赖 System。
     *
     * 历史上 modules/Auth 的导入导出 Job 直接调用 System 的 NotificationService，
     * 与 System → Auth 的模型依赖构成环。现已改为走内核契约，这里锁住不再回流。
     */
    public function test_auth_module_does_not_depend_on_system(): void
    {
        $this->assertSame(
            [],
            $this->refsTo(base_path('modules/Auth'), 'System'),
            'modules/Auth 依赖了 Modules\System，模块间出现环。'
            .'发送通知请 type-hint App\Contracts\TaskNotification，'
            .'不要直接引用 System 的 Service 或 Model。'
        );
    }

    /**
     * Business 与 System 之间当前零引用，是三条边界里最干净的一条。
     * 守住它，业务模块就不会悄悄长出对系统模块的依赖。
     */
    public function test_business_module_does_not_depend_on_system(): void
    {
        $this->assertSame(
            [],
            $this->refsTo(base_path('modules/Business'), 'System'),
            'modules/Business 依赖了 Modules\System，模块间出现横向耦合。'
        );
    }

    /**
     * 所有模块间依赖边都必须落在白名单内。
     *
     * 上面两条只点名了 Auth↔System、Business↔System 两条边，本用例兜住其余所有
     * 组合（含 System→Business、任何指向 Auth 的反向边），并在结构上保证无环。
     * 两条定向用例保留不动：它们的提示更具体，指出该走哪个内核契约。
     */
    public function test_module_dependencies_stay_within_allowlist(): void
    {
        $edges = $this->moduleEdges();
        $unknown = array_values(array_diff(array_keys($edges), self::ALLOWED_MODULE_EDGES));

        $this->assertSame(
            [],
            $unknown,
            '模块间出现了不在白名单里的依赖边。'
            .'当前边：'.implode(', ', array_keys($edges))
            .'；允许：'.implode(', ', self::ALLOWED_MODULE_EDGES)
            ."\n有模块引用了另一模块，先问一句：能不能改走 App\Contracts 里的内核契约？"
            ."不能时在 ALLOWED_MODULE_EDGES 里登记这条边并写明理由。"
        );
    }

    /**
     * 共享内核不得依赖任何模块（依赖方向只能向内）。
     *
     * 已登记的例外：bootstrap/app.php 的 stock.snapshot 别名指向 Business 的
     * StockSnapshotMiddleware，但作用域是「全部管理端请求」，属横切关注点，
     * 留在内核信封更诚实——所以本用例只查 app/，不含 bootstrap/。
     */
    public function test_kernel_does_not_depend_on_modules(): void
    {
        $bad = [];

        foreach (['Auth', 'Business', 'System'] as $module) {
            foreach ($this->refsTo(base_path('app'), $module) as $file) {
                $bad[] = "Modules\\$module  ←  $file";
            }
        }

        $this->assertSame(
            [],
            $bad,
            'app/ 依赖了模块代码（依赖倒置）。共享内核不得 import Modules\\：'
            ."\n".implode("\n", $bad)
            ."模块自有中间件请放到 modules/<M>/Http/Middleware/；"
            ."中间件别名仍统一在 bootstrap/app.php 的 middlewareAliases() 里注册"
            ."（别名表是全局中间件词汇表，属配置而非代码耦合）。"
            ."config/ 与 bootstrap/ 里指向模块类的配置例外已逐处登记，见 docs/MODULARIZATION.md 第三节。"
        );
    }

    /**
     * 每个模块必须自持路由与迁移目录。
     * 缺一项说明模块还是「挂在集中目录里的命名空间」，不算模块化。
     *
     * tests/ 只要求「目录存在」，不要求里面有 .php：System 目前尚无模块专属测试，
     * 靠 Feature/.gitkeep 占位。占位不是风格问题——phpunit.xml 已声明该 testsuite，
     * 目录缺失会让整个 phpunit run 中止（见 test_declared_testsuite_directories_exist）。
     */
    public function test_every_module_owns_routes_and_migrations(): void
    {
        $modules = array_values(array_map(
            'basename',
            glob(base_path('modules/*'), GLOB_ONLYDIR) ?: []
        ));

        $this->assertNotEmpty($modules, 'modules/ 下没有任何模块目录');

        $missing = [];
        foreach ($modules as $module) {
            foreach (['routes', 'database/migrations'] as $sub) {
                $path = base_path("modules/$module/$sub");
                if (!is_dir($path) || empty(glob("$path/*.php"))) {
                    $missing[] = "modules/$module/$sub";
                }
            }

            if (!is_dir(base_path("modules/$module/tests"))) {
                $missing[] = "modules/$module/tests";
            }
        }

        $this->assertSame(
            [],
            $missing,
            '以下模块缺少自持目录或目录为空：'.implode(', ', $missing)
        );
    }

    /**
     * phpunit.xml 声明的每个 <directory> 必须真实存在。
     *
     * 这是踩过的坑：modules/System/tests 当初只有本地空目录、git 不跟踪，
     * 检出后目录不存在，PHPUnit 11 不是跳过那一个 testsuite，而是中止整个 run
     * （exit 2，零个测试执行）。于是 CI 全红，而且红的不是任何一条断言。
     * 空目录占位用 .gitkeep（无 .php 后缀，不会被当成测试收进去）。
     */
    public function test_declared_testsuite_directories_exist(): void
    {
        $xml = (string) file_get_contents(base_path('phpunit.xml'));

        preg_match_all('/<directory>(.*?)<\/directory>/', $xml, $matches);
        $declared = array_values(array_unique(
            array_map('trim', $matches[1] ?? [])
        ));

        $this->assertNotEmpty($declared, 'phpunit.xml 里没扫到任何 <directory>');

        $missing = array_values(array_filter(
            $declared,
            fn ($rel) => !is_dir(base_path($rel))
        ));

        $this->assertSame(
            [],
            $missing,
            'phpunit.xml 声明了不存在的目录：'.implode(', ', $missing)
            ."（PHPUnit 遇到声明的目录缺失会中止整个 run，不是跳过该 testsuite。"
            .'空目录 git 不跟踪，放一个 .gitkeep 占位。）'
        );
    }
}
