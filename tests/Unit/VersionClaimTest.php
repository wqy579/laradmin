<?php

namespace Tests\Unit;

use Composer\Semver\Semver;
use PHPUnit\Framework\TestCase;

/**
 * 版本声明守卫
 *
 * composer.json / composer.lock 才是版本的事实来源。README、欢迎页、部署文档里写死的
 * 版本号一旦漂移，不会有任何报错——只有使用者盯着 GitHub 徽章猜环境时才会踩坑。
 *
 * 首次批量导入时就栽过：README 写 Laravel 13、欢迎页写 Laravel 12，而 composer.lock
 * 自始至终锁的是 11.56.1。同一个提交里两个数字互相矛盾，说明它们是模板文案，不是从
 * 代码里读出来的。
 *
 * 不维护手写名单：扫文档里的每一个 Laravel 大版本号，与 composer.json 的约束比对。
 * 新增文档只要落到 SCAN_TARGETS 覆盖的目录里，就自动纳入检查，不需要人记得回来补。
 *
 * 不继承 Tests\TestCase：这是纯文件扫描，引导 Laravel 应用（连带 SQLite 内存库）只会
 * 给这条检查增加无谓的启动成本。
 */
class VersionClaimTest extends TestCase
{
    /**
     * 会被使用者当成环境说明读的文档。
     *
     * CHANGELOG / 升级指南类文件故意不收：那里的历史版本引用是正当的（「从 Laravel 11
     * 升级到 12」），套上这个校验只会逼着作者把正确的历史记录改坏。
     */
    private const SCAN_TARGETS = [
        'README.md',
        'DEPLOY.md',
        'resources/views/**/*.php',
        'public/docs/**/*.html',
        'docs/**/*.md',
    ];

    public function test_every_documented_laravel_major_matches_composer_constraint(): void
    {
        $expected = $this->composerFrameworkMajor();
        $this->assertNotNull($expected, 'composer.json 没有声明 laravel/framework 约束');

        $files = $this->documentedFiles();
        $this->assertNotEmpty($files, '一个文档都没扫到，SCAN_TARGETS 可能写错了');

        $claims = [];
        foreach ($files as $path) {
            $this->assertFileExists($path, "待检文档缺失：$path");
            $source = (string) file_get_contents($path);

            // 正文：Laravel 11 / Laravel 11.56 / Laravel 11.56.1
            foreach ($this->majors($source, '/Laravel\s+(\d{2})/i') as $major) {
                $claims[$path][] = (int) $major;
            }
            // shields.io 徽章：Laravel-11.0-red.svg
            foreach ($this->majors($source, '/Laravel-(\d{2})\.\d+/i') as $major) {
                $claims[$path][] = (int) $major;
            }
        }

        $this->assertNotEmpty($claims, '没扫到任何 Laravel 版本声明，正则可能失效了');

        $violations = [];
        foreach ($claims as $path => $majors) {
            foreach (array_unique($majors) as $major) {
                if ($major !== $expected) {
                    $violations[] = sprintf(
                        '%s：声明 Laravel %d，但 composer.json 约束的是 ^%d.0',
                        $path,
                        $major,
                        $expected
                    );
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    /**
     * composer.lock 的锁定版本必须落在 composer.json 的约束内。
     *
     * 只改 composer.json 而不跑 composer update（或反过来），约束与锁定版本就会各说
     * 各话，README 写哪个数字都会有一半是对的。
     */
    public function test_locked_framework_version_satisfies_composer_constraint(): void
    {
        $constraint = $this->composerConstraint();
        $this->assertNotNull($constraint, 'composer.json 没有声明 laravel/framework 约束');

        $locked = $this->lockedFrameworkVersion();
        $this->assertNotNull($locked, 'composer.lock 里没有 laravel/framework');

        $this->assertTrue(
            Semver::satisfies($locked, $constraint),
            "composer.lock 锁定 laravel/framework {$locked}，不满足 composer.json 的 {$constraint} —— 请跑 composer update laravel/framework 并重新提交 composer.lock"
        );
    }

    /**
     * composer.json 里 laravel/framework 约束的大版本号。
     *
     * 支持 ^11.0 / 11.* / 11.0.* / ~11.0 这类写法；解析不出来就返回 null，
     * 让断言给出「约束缺失」而不是一个误报的版本号。
     */
    private function composerFrameworkMajor(): ?int
    {
        $constraint = $this->composerConstraint();
        if ($constraint === null) {
            return null;
        }

        if (preg_match('/^\^?\s*(\d+)\.\d+/', $constraint, $match)) {
            return (int) $match[1];
        }

        if (preg_match('/^\d+\.?\*?$/m', $constraint) && is_numeric($constraint)) {
            return (int) explode('.', $constraint)[0];
        }

        return null;
    }

    private function composerConstraint(): ?string
    {
        $composer = json_decode((string) file_get_contents($this->root().'composer.json'), true);
        $this->assertIsArray($composer, 'composer.json 无法解析');

        return $composer['require']['laravel/framework'] ?? null;
    }

    private function lockedFrameworkVersion(): ?string
    {
        $lock = json_decode((string) file_get_contents($this->root().'composer.lock'), true);
        $this->assertIsArray($lock, 'composer.lock 无法解析');

        foreach ($lock['packages'] as $package) {
            if ($package['name'] === 'laravel/framework') {
                // lock 里写成 v11.56.1，Semver 不接受前缀 v
                return ltrim($package['version'], 'v');
            }
        }

        return null;
    }

    /**
     * 把 SCAN_TARGETS 展开成实际存在的文件列表。
     *
     * 字面路径直接收录（缺失时由调用方的 assertFileExists 报错，那是真问题）；
     * 带通配符的按目录递归收集，目录不存在则静默跳过（docs/ 在部分分支上就不存在）。
     */
    private function documentedFiles(): array
    {
        $files = [];
        foreach (self::SCAN_TARGETS as $target) {
            $absolute = $this->root().$target;

            if (str_contains($target, '*')) {
                $base = (string) preg_replace('/\/\*\*\/[^\/]*$/', '', $target);
                if (! is_dir($this->root().$base)) {
                    continue;
                }

                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root().$base)) as $item) {
                    if ($item->isFile()) {
                        $files[] = ltrim(str_replace($this->root(), '', $item->getPathname()), '/');
                    }
                }

                continue;
            }

            if (is_file($absolute)) {
                $files[] = $target;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * 抽出正则捕获组里的大版本号。
     */
    private function majors(string $source, string $pattern): array
    {
        if (! preg_match_all($pattern, $source, $matches)) {
            return [];
        }

        return $matches[1];
    }

    private function root(): string
    {
        return dirname(__DIR__, 2).'/';
    }
}
