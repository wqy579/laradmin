<?php

namespace Tests\Archive\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Archive\Models\HelpDoc;
use Modules\Archive\Services\HelpDocService;
use Tests\TestCase;

/**
 * F6 块3：帮助中心。
 *
 * 验收点：
 *  - 菜单迁移把两个页面挂到 data 父菜单下，按钮权限点「点号 + 冒号」两种写法都在
 *  - 建单 → 查询 → 编辑 → 发布 → 撤回 → 软删全链路
 *  - 富文本落库前必须过 HtmlSanitizer：接口层就是安全边界，
 *    这里断言的是**库里存的那一列**，不是响应体（响应体回显干净没意义）
 *  - content_text 由正文派生、不接受外部传入（否则搜索能命中正文里没有的词）
 *  - slug：中文标题走 id 兜底、英文标题转写、重名自动加 -2、手填重复明确 422
 *  - 正文为空不许发布；阅读数只在 read 上 +1
 *
 * ID 一律从响应里取，不写死自增值：本地 sqlite、CI MySQL，两边首行 id 不保证一致。
 */
class HelpDocFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['real_name' => '帮助管理员']);
    }

    public function test_menu_migrations_registered_both_pages_under_data_parent(): void
    {
        $this->assertDatabaseHas('auth_permission', [
            'name' => 'data.help',
            'path' => '/data/help',
            'component' => 'data/help/index',
        ]);
        $this->assertDatabaseHas('auth_permission', [
            'name' => 'data.download',
            'path' => '/data/download',
            'component' => 'data/download/index',
        ]);
        // 前端 v-permission 用冒号形式，两种写法都要登记。
        // 发布/撤回不单开权限点（它属于"能编辑这篇文档"的一部分），
        // 所以这里断言的正是页面上真正用到的那几个码，不多不少
        $this->assertDatabaseHas('auth_permission', ['name' => 'data:help:create']);
        $this->assertDatabaseHas('auth_permission', ['name' => 'data:help:edit']);
        $this->assertDatabaseHas('auth_permission', ['name' => 'data:download:download']);
        $this->assertDatabaseHas('auth_permission', ['name' => 'data.download.delete']);
    }

    public function test_store_then_query_then_update_then_publish_then_withdraw_then_soft_delete(): void
    {
        $resp = $this->postJson('/admin/data/help', $this->payload([
            'title' => 'How To Open Books',
            'content' => '<p>先建公司档案</p>',
        ]))
            ->assertOk()
            ->assertJsonPath('message', '已创建')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.slug', 'how-to-open-books');

        $id = $resp->json('data.id');
        $this->assertNotEmpty($id);

        // 列表不返回 content（正文可能几百 KB，列表页用不到），只给派生字段
        $this->getJson('/admin/data/help?keyword=Open Books&category=开账')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonStructure(['data' => ['list', 'total']]);

        $this->putJson("/admin/data/help/{$id}", $this->payload([
            'title' => 'How To Open Books',
            'content' => '<p>先建公司档案，再建事业部</p>',
            'sort' => 5,
        ]))
            ->assertOk()
            ->assertJsonPath('message', '已更新');

        $published = $this->postJson("/admin/data/help/{$id}/publish")->assertOk();
        $published->assertJsonPath('data.status', 'published');
        $this->assertNotNull($published->json('data.published_at'));

        $this->postJson("/admin/data/help/{$id}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        // 撤回不抹掉首次发布时间
        $this->assertNotNull(HelpDoc::find($id)->published_at);

        $this->deleteJson("/admin/data/help/{$id}")
            ->assertOk()
            ->assertJsonPath('message', '已删除');

        $this->getJson("/admin/data/help/{$id}")->assertStatus(404);
        $this->assertSoftDeleted('archive_help_docs', ['id' => $id]);
    }

    public function test_dangerous_content_is_stored_sanitized(): void
    {
        $id = $this->postJson('/admin/data/help', $this->payload([
            'title' => 'XSS 探针',
            'content' => '<p>正常段落</p><script>alert(1)</script>'
                .'<img src=x onerror="alert(2)">'
                .'<a href="javascript:alert(3)">点我</a>',
        ]))->json('data.id');

        $stored = HelpDoc::find($id)->content;

        // 断言库里的列：接口回显干净没用，落到库里再渲染才是真出事
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
        $this->assertStringContainsString('<p>正常段落</p>', $stored);
        // 链接本身保留（白名单标签），只是去掉危险协议 —— 正文里的锚点/外链是常态
        $this->assertStringContainsString('<a>点我</a>', $stored);
    }

    public function test_content_text_is_derived_and_searchable_but_not_client_controlled(): void
    {
        $id = $this->postJson('/admin/data/help', $this->payload([
            'title' => '派生正文',
            'content' => '<h2>开账三步</h2><p>存货<b>期初</b>录入</p>',
            // 客户端硬塞派生列：不该生效（否则搜索能命中正文里根本没有的词）
            'content_text' => '这里是伪造的可搜索文本 绝密关键词',
        ]))->assertOk()->json('data.id');

        $doc = HelpDoc::find($id);
        $this->assertStringNotContainsString('绝密关键词', (string) $doc->content_text);
        $this->assertStringContainsString('开账三步', (string) $doc->content_text);
        $this->assertStringContainsString('期初', (string) $doc->content_text);
        // 标签一律不进纯文本
        $this->assertStringNotContainsString('<', (string) $doc->content_text);

        // 摘要留空时由正文派生，省得每篇都要手填一遍
        $this->assertStringContainsString('开账三步', (string) $doc->summary);
    }

    public function test_chinese_title_falls_back_to_id_slug_and_duplicate_title_gets_suffix(): void
    {
        $first = $this->postJson('/admin/data/help', [
            'title' => '开账向导使用说明',
            'category' => '开账',
            'content' => '<p>正文甲</p>',
        ])->assertOk();

        // 纯中文标题转写为空 -> 用 id 兜底，避免出现一片空 slug 撞唯一索引
        $this->assertSame('doc-'.base_convert((string) $first->json('data.id'), 10, 36), $first->json('data.slug'));

        $second = $this->postJson('/admin/data/help', [
            'title' => '开账向导使用说明',
            'category' => '开账',
            'content' => '<p>正文乙</p>',
        ])->assertOk();

        // 同上，第二条也是 id 兜底，所以不会和第一条撞车
        $this->assertNotSame($first->json('data.slug'), $second->json('data.slug'));

        $a = $this->postJson('/admin/data/help', [
            'title' => 'Help Open Books',
            'category' => '开账',
            'content' => '<p>甲</p>',
        ])->assertOk()->json('data.slug');
        $b = $this->postJson('/admin/data/help', [
            'title' => 'Help Open Books',
            'category' => '开账',
            'content' => '<p>乙</p>',
        ])->assertOk()->json('data.slug');

        $this->assertSame('help-open-books', $a);
        $this->assertSame('help-open-books-2', $b);
    }

    public function test_explicit_duplicate_slug_is_rejected_with_a_readable_reason(): void
    {
        $this->postJson('/admin/data/help', $this->payload(['title' => 'Doc A', 'slug' => 'my-guide']))
            ->assertOk();

        $this->postJson('/admin/data/help', $this->payload(['title' => 'Doc B', 'slug' => 'my-guide']))
            ->assertStatus(422)
            ->assertJsonPath('message', '别名已被占用，请换一个');

        // 手填别名只允许小写字母数字连字符：斜杠会把深链拼到别的路径上去
        $this->postJson('/admin/data/help', $this->payload(['title' => 'Doc C', 'slug' => '../etc']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_publish_refuses_a_document_whose_content_is_only_markup(): void
    {
        // 正文只有 script 和空标签：净化后什么都不剩，发布出去就是一张白页
        $id = $this->postJson('/admin/data/help', $this->payload([
            'title' => '空正文',
            'content' => '<script>alert(1)</script><p></p><div><br></div>',
        ]))->assertOk()->json('data.id');

        $this->postJson("/admin/data/help/{$id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('message', '正文为空，不能发布');

        $this->assertSame('draft', HelpDoc::find($id)->status);
    }

    public function test_read_increments_views_but_editing_does_not(): void
    {
        $id = $this->postJson('/admin/data/help', $this->payload(['title' => '阅读计数']))->assertOk()->json('data.id');

        $this->postJson("/admin/data/help/{$id}/read")->assertOk()->assertJsonPath('data.views', 1);
        $this->postJson("/admin/data/help/{$id}/read")->assertOk()->assertJsonPath('data.views', 2);

        // 编辑时要 show 同一篇文档拿正文，不能算阅读 —— 否则编辑十次阅读数 +10
        $this->getJson("/admin/data/help/{$id}")->assertOk();
        $this->putJson("/admin/data/help/{$id}", $this->payload(['title' => '阅读计数', 'sort' => 1]))->assertOk();

        $this->assertSame(2, (int) HelpDoc::find($id)->views);
    }

    public function test_categories_return_every_group_with_counts(): void
    {
        $this->postJson('/admin/data/help', $this->payload(['title' => 'A', 'category' => '开账']))->assertOk();
        $this->postJson('/admin/data/help', $this->payload(['title' => 'B', 'category' => '开账']))->assertOk();

        $resp = $this->getJson('/admin/data/help/categories')->assertOk();

        // 常量里的分组即使一篇文档都没有也要出现，否则前端左侧导航会缺项
        $names = array_column($resp->json('data.list'), 'name');
        foreach (HelpDocService::CATEGORIES as $name) {
            $this->assertContains($name, $names);
        }
        $open = collect($resp->json('data.list'))->firstWhere('name', '开账');
        $this->assertSame(2, $open['count']);
        $this->assertSame(2, $resp->json('data.total'));
    }

    public function test_preview_returns_sanitized_html_and_flags_executable_input(): void
    {
        $resp = $this->postJson('/admin/data/help/preview', [
            'content' => '<p>可用段落</p><script>alert(1)</script>',
        ])
            ->assertOk()
            ->assertJsonPath('data.content', '<p>可用段落</p>')
            ->assertJsonPath('data.had_executable', true);

        $this->assertStringNotContainsString('script', $resp->json('data.content'));
        $this->assertSame('可用段落', $resp->json('data.content_text'));

        // 预览不落库：它只是"所见即所得"的镜像，点了保存才算写
        $this->assertSame(0, HelpDoc::count());

        $clean = $this->postJson('/admin/data/help/preview', ['content' => '<p>干净</p>'])->assertOk();
        $this->assertFalse($clean->json('data.had_executable'));
    }

    public function test_title_and_category_are_required_and_status_is_enumerated(): void
    {
        $this->postJson('/admin/data/help', ['content' => '<p>x</p>'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'category']);

        $this->postJson('/admin/data/help', $this->payload(['title' => 'X', 'status' => 'archived']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => '示例文档',
            'category' => '开账',
            'content' => '<p>示例正文</p>',
        ], $overrides);
    }
}
