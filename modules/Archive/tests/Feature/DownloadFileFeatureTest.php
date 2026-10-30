<?php

namespace Tests\Archive\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Archive\Models\DownloadFile;
use Modules\Archive\Services\DownloadFileService;
use Tests\TestCase;
use ZipArchive;

/**
 * F6 块3：下载中心。
 *
 * 验收点（安全为主）：
 *  - 扩展名白名单 + 真实 MIME 自洽：改后缀骗不过去（.html 伪装成 .xlsx 必拒）
 *  - 磁盘名是随机串，与展示名彻底解耦 —— 展示名里带 ../ 也定位不到别的文件
 *  - 超大拒；下架的文件接口层就拦（只藏前端按钮不算拦住）
 *  - 下载成功累加次数；软删只删记录、磁盘文件留着
 *
 * 磁盘用 Storage::fake('local')：真往 storage/app/private 写会污染开发环境，
 * CI 上跑完留下的垃圾也没人收。
 *
 * 造文件内容只用**真容器字节**（zip 用 ZipArchive 现造、png 用 1×1 魔数），
 * 不能用随机串 —— 那样 finfo 探到的是 application/octet-stream，
 * 白名单里恰好接受 octet-stream，测试会"通过"但其实什么都没验。
 *
 * ID 一律从响应里取，不写死自增值：本地 sqlite、CI MySQL 不保证一致。
 */
class DownloadFileFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAsAdmin(['real_name' => '资料管理员']);
    }

    public function test_upload_accepts_office_types_and_stores_under_a_random_name(): void
    {
        $resp = $this->postJson('/admin/data/download', [
            'file' => File::createWithContent('存货期初模板.xlsx', $this->zipBytes()),
            'category' => '模板',
            'version' => 'V1.2',
            'description' => '开账第 3 步用',
        ])
            ->assertOk()
            ->assertJsonPath('message', '已上传')
            ->assertJsonPath('data.file_name', '存货期初模板.xlsx')
            ->assertJsonPath('data.extension', 'xlsx')
            ->assertJsonPath('data.status', 'published');

        // 新建响应的模型实例没带 download_count（DB 默认值，create 时不回填），
        // 所以计数从库里读，不去断言一个响应里本就不存在的键
        $this->assertSame(0, (int) DownloadFile::first()->download_count);

        $id = $resp->json('data.id');
        $stored = $resp->json('data.stored_name');

        // 随机名与展示名解耦：这条是"展示名里塞什么都影响不到读哪个文件"的依据
        $this->assertNotSame($resp->json('data.file_name'), $stored);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}\.xlsx$/', $stored);
        $this->assertTrue(Storage::disk('local')->exists('downloads/'.$stored));

        $this->assertSame('资料管理员', DownloadFile::find($id)->created_by_name);
        $this->assertGreaterThan(0, (int) DownloadFile::find($id)->size);
    }

    public function test_display_name_is_cleaned_of_path_and_control_characters(): void
    {
        // 展示名会进 Content-Disposition 和前端列表，两处都不做二次编码，
        // 所以入库前就必须是"看起来像文件名"的东西（换行更是响应头注入）
        $resp = $this->postJson('/admin/data/download', [
            'file' => File::createWithContent("../../etc/passwd\n.xlsx", $this->zipBytes()),
            'category' => '模板',
        ])->assertOk();

        $name = $resp->json('data.file_name');
        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('..', $name);
        $this->assertStringNotContainsString("\n", $name);
        $this->assertStringEndsWith('.xlsx', $name);
    }

    public function test_html_disguised_as_a_whitelisted_extension_is_rejected_by_the_mime_check(): void
    {
        // 这就是本次的核心攻击：改个后缀骗过白名单，下载回来当 html 执行
        $html = "<!DOCTYPE html><script>alert('xss')</script>";

        foreach (['伪装.xlsx', '伪装.pdf', '伪装.csv'] as $name) {
            $resp = $this->postJson('/admin/data/download', [
                'file' => File::createWithContent($name, $html),
                'category' => '模板',
            ])->assertStatus(422);

            // 拒绝原因必须带出"探测到了什么"：只说"格式错误"用户根本不知道要改什么
            $this->assertStringContainsString('与扩展名不符', $this->fileError($resp), $name);
            $this->assertStringContainsString('text/html', $this->fileError($resp), $name);
        }

        $this->assertSame(0, DownloadFile::count());
    }

    public function test_extensions_outside_the_whitelist_are_rejected_even_with_matching_content(): void
    {
        // 内容是合法 zip 也没用：白名单外的扩展名在读内容之前就被拒
        foreach (['shell.php' => $this->zipBytes(), 'vector.svg' => '<svg onload="x"/>', 'page.html' => '<b>x</b>', 'noext' => 'x'] as $name => $body) {
            $resp = $this->postJson('/admin/data/download', [
                'file' => File::createWithContent($name, $body),
                'category' => '模板',
            ])->assertStatus(422);

            $this->assertStringContainsString('不支持的文件类型', $this->fileError($resp), $name);
        }

        $this->assertSame(0, DownloadFile::count());
        Storage::disk('local')->assertMissing('downloads/shell.php');
    }

    public function test_oversize_file_is_rejected(): void
    {
        // 上限 20MB，这里 20MB+1KB。大小在 MIME 之前判，所以不必造合法内容
        $resp = $this->postJson('/admin/data/download', [
            'file' => File::create('big.pdf', DownloadFileService::MAX_SIZE + 1024),
            'category' => '模板',
        ])
            ->assertStatus(422);

        $this->assertStringContainsString('20MB', $this->fileError($resp));
        $this->assertSame(0, DownloadFile::count());
    }

    public function test_download_streams_the_file_and_counts_once_per_hit(): void
    {
        $id = $this->postJson('/admin/data/download', [
            'file' => File::createWithContent('操作手册.xlsx', $this->zipBytes()),
            'category' => '手册',
        ])->assertOk()->json('data.id');

        $this->assertStringContainsString('sheet', $this->getJson("/admin/data/download/{$id}/download")->assertOk()->streamedContent());
        $this->getJson("/admin/data/download/{$id}/download")->assertOk();

        $this->assertSame(2, (int) DownloadFile::find($id)->download_count);
    }

    public function test_disabled_file_and_missing_file_are_both_refused(): void
    {
        $id = $this->postJson('/admin/data/download', [
            'file' => File::createWithContent('作废模板.xlsx', $this->zipBytes()),
            'category' => '模板',
        ])->assertOk()->json('data.id');

        // 下架的语义就是"别再发出去了"，接口得自己守住
        $this->putJson("/admin/data/download/{$id}", ['name' => '作废模板', 'category' => '模板', 'status' => 'disabled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled');

        $this->getJson("/admin/data/download/{$id}/download")
            ->assertStatus(422)
            ->assertJsonPath('message', '该文件已下架');

        $this->putJson("/admin/data/download/{$id}", ['name' => '作废模板', 'category' => '模板', 'status' => 'published'])
            ->assertOk();

        // 记录在、磁盘没了：给可解释的提示，而不是 500
        Storage::disk('local')->delete('downloads/'.DownloadFile::find($id)->stored_name);
        $this->getJson("/admin/data/download/{$id}/download")
            ->assertStatus(404)
            ->assertJsonPath('message', '文件已丢失，请联系管理员重新上传');

        // show 会把 missing 带出来，列表页据此提示"重新上传"
        $this->getJson("/admin/data/download/{$id}")->assertOk()->assertJsonPath('data.missing', true);
    }

    public function test_soft_delete_keeps_the_file_on_disk(): void
    {
        $resp = $this->postJson('/admin/data/download', [
            'file' => File::createWithContent('历史模板.xlsx', $this->zipBytes()),
            'category' => '模板',
        ])->assertOk();

        $id = $resp->json('data.id');
        $stored = $resp->json('data.stored_name');

        $this->deleteJson("/admin/data/download/{$id}")->assertOk()->assertJsonPath('message', '已删除');

        $this->assertSoftDeleted('archive_download_files', ['id' => $id]);
        // 模板类文件常被历史单据引用，误删后 restore 要能立刻恢复下载
        $this->assertTrue(Storage::disk('local')->exists('downloads/'.$stored));
    }

    public function test_index_filters_by_keyword_category_and_status(): void
    {
        $this->postJson('/admin/data/download', ['file' => File::createWithContent('存货期初.xlsx', $this->zipBytes()), 'category' => '模板'])->assertOk();
        $this->postJson('/admin/data/download', ['file' => File::createWithContent('打印驱动.png', $this->pngBytes()), 'category' => '其它'])->assertOk();

        $this->getJson('/admin/data/download?keyword=期初')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/admin/data/download?category=其它')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/admin/data/download?status=disabled')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/admin/data/download?keyword=%25期初')->assertOk()->assertJsonPath('data.total', 0);

        $cats = $this->getJson('/admin/data/download/categories')->assertOk();
        $this->assertSame(2, $cats->json('data.total'));
        // 空分组也要出现在导航里，否则前端左侧缺项
        $this->assertSame(0, collect($cats->json('data.list'))->firstWhere('name', '手册')['count']);
    }

    /** 取 file 字段的第一条拒绝原因（422 的 data/errors 结构见 assertJsonValidationErrors 用法） */
    private function fileError(TestResponse $resp): string
    {
        return (string) ($resp->json('errors.file.0') ?? $resp->json('message'));
    }

    /**
     * 真 zip 容器字节。
     *
     * xlsx/docx/pdf-之类在 finfo 眼里都是 zip，直接塞随机串会被探成
     * application/octet-stream，而白名单接受 octet-stream —— 那测试就空转了。
     * CI 装了 ext-zip（见 .github/workflows/tests.yml），但不装 gd，
     * 所以 png 只能用魔数字符串，不能 imagepng()。
     */
    private function zipBytes(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'f6zip').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('sheet.xml', '<sheet>ok</sheet>');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** 1×1 PNG（纯字节，不依赖 gd） */
    private function pngBytes(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
        );
    }
}
