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
            .'模块自有中间件请放到 modules/<M>/Http/Middleware/，'
            .'别名在该模块 Provider 的 boot() 里 Route::middlewareAliases() 注册。'
        );
    }

    /**
     * 每个模块必须自持路由与迁移目录。
     * 缺一项说明模块还是「挂在集中目录里的命名空间」，不算模块化。
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
        }

        $this->assertSame(
            [],
            $missing,
            '以下模块缺少自持目录或目录为空：'.implode(', ', $missing)
        );
    }
}
