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

            $content = (string) file_get_contents($file->getPathname());

            if (str_contains($content, $needle)) {
                $hits[] = $file->getPath();
            }
        }

        return array_values(array_unique($hits));
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
