<?php

namespace Tests\Archive\Unit;

use Modules\Archive\Support\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * F6 块3 安全验收主体：富文本 XSS 过滤。
 *
 * 这个类是帮助中心"无脚本注入"这条验收线的证据，所以断言写得偏执：
 * 每条 payload 都同时断言两件事 ——
 *  1. containsExecutable(clean(payload)) === false：输出里没有任何可执行痕迹
 *     （标签、on* 属性、javascript:/vbscript:/data:text/html 协议）；
 *  2. 精确输出等于预期：防止"看起来干净但其实把整段内容抹平了"这种假安全。
 *
 * 覆盖面按攻击类别组织，不按 payload 数量堆：脚本标签 / 事件属性 / 伪协议 /
 * 嵌套与解析差异(mXSS) / 命名空间(svg、math) / 文档级声明与注释 / 结构越权(body、base、meta)。
 */
class HtmlSanitizerTest extends TestCase
{
    /** 每条 [标题, 输入, 期望输出] */
    public static function payloadProvider(): array
    {
        return [
            '裸 script 标签' => [
                '<script>alert(1)</script>',
                '',
            ],
            'script 带属性与闭合混淆' => [
                '<script type="text/javascript">alert(document.cookie)</script>',
                '',
            ],
            'script 大小写混排 + 换行拆标签名' => [
                "<ScRiPt\n>alert(1)</ScRiPt>",
                '',
            ],
            'img onerror 事件属性' => [
                '<p>正常文字</p><img src=x onerror="alert(1)">',
                '<p>正常文字</p><img src="x">',
            ],
            '属性未加引号的 onmouseover' => [
                '<p onmouseover=alert(1)>hi</p>',
                '<p>hi</p>',
            ],
            'svg onload（命名空间标签整棵剪掉）' => [
                '<svg onload="alert(1)"><circle /></svg>',
                '',
            ],
            'iframe + javascript 协议' => [
                '<iframe src="javascript:alert(1)"></iframe>',
                '',
            ],
            'a href javascript: 原样' => [
                '<a href="javascript:alert(1)">点我</a>',
                '<a>点我</a>',
            ],
            'a href javascript: 大小写混排' => [
                '<a href="JaVaScRiPt:alert(1)">x</a>',
                '<a>x</a>',
            ],
            'a href 前置空格' => [
                '<a href="   javascript:alert(1)">x</a>',
                '<a>x</a>',
            ],
            'a href 制表符实体拆词' => [
                '<a href="jav&#x09;ascript:alert(1)">x</a>',
                '<a>x</a>',
            ],
            'a href 首字母十进制实体' => [
                '<a href="&#106;avascript:alert(1)">x</a>',
                '<a>x</a>',
            ],
            'a href 冒号实体' => [
                '<a href="javascript&#58;alert(1)">x</a>',
                '<a>x</a>',
            ],
            'a href 双重实体编码' => [
                '<a href="&amp;#106;avascript:alert(1)">x</a>',
                '<a>x</a>',
            ],
            // NUL 会让 libxml 在属性中途截断，序列化结果不"漂亮"，但绝不含伪协议：
            // 这种畸形输入只断言安全性，不断言精确输出（断言精确输出等于把解析器
            // 的截断行为当契约锁死，换 libxml 版本就会红）
            'a href 内嵌 NUL' => [
                "<a href=\"java\0script:alert(1)\">x</a>",
                null,
            ],
            'a href vbscript 协议' => [
                '<a href="vbscript:msgbox(1)">x</a>',
                '<a>x</a>',
            ],
            'body onload（外层结构不得漏出）' => [
                '<body onload="alert(1)">hi</body>',
                'hi',
            ],
            'style 标签含 @import' => [
                '<style>@import "http://evil/x.css";body{display:none}</style><p>ok</p>',
                '<p>ok</p>',
            ],
            'form + action 伪协议' => [
                '<form action="javascript:alert(1)"><input name=a></form>',
                '',
            ],
            'object data data:text/html' => [
                '<object data="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=="></object>',
                '',
            ],
            'base href 劫持全站相对链接' => [
                '<base href="javascript:alert(1)//"><a href="#x">keep</a>',
                '<a href="#x">keep</a>',
            ],
            'meta refresh 跳转' => [
                '<meta http-equiv="refresh" content="0;url=javascript:alert(1)"><p>keep</p>',
                '<p>keep</p>',
            ],
            'textarea 解析错位（未闭合标签）' => [
                '<textarea><script>alert(1)</script></textarea>',
                '',
            ],
            // noscript 在剪除名单里，整棵子树连标题属性一起消失（不是"留下 x"）：
            // 这条 payload 的价值在于 title 里那段 </noscript><img onerror> 绝不能被
            // 二次解析成真的 img，输出成空串正是最稳的结果
            'noscript mXSS（浏览器解析差异）' => [
                '<noscript><p title="</noscript><img src=x onerror=alert(1)>">x</p></noscript>',
                '',
            ],
            'math mtext style mXSS' => [
                '<math><mtext><table><mglyph><style><!--</style><img title="--><img src=1 onerror=alert(1)>">--></style></mglyph></table></mtext></math>',
                '',
            ],
            // 根标记每次随机（data-f6-<16hex>），攻击者塞的固定串只是个普通未知属性：
            // div 本身在白名单里所以保留（脱壳只针对白名单外标签），data-* 被清，script 被剪。
            // 这条真正要证的是"塞标记跳过清洗"这条路走不通
            '攻击者自己塞根标记试图跳过清洗' => [
                '<div data-f6-root="x"><script>alert(1)</script></div><p>keep</p>',
                '<div></div><p>keep</p>',
            ],
            'xml 声明改编码' => [
                '<?xml version="1.0" encoding="UTF-16"?><p>keep</p>',
                '<p>keep</p>',
            ],
            'HTML 注释里的条件注释' => [
                '<!--[if IE]><script>alert(1)</script><![endif]--><p>keep</p>',
                '<p>keep</p>',
            ],
            // HTML 解析器不认 CDATA（那是 XML 的东西），所以 <![CDATA[ 与 ]]> 本身就是
            // 普通文本；中间的 <script> 仍按标签被剪掉。剩下的转义字面量无害
            'CDATA 段' => [
                '<div><![CDATA[<script>alert(1)</script>]]></div>',
                '<div>&lt;![CDATA[]]&gt;</div>',
            ],
            'on* + class + style 全清' => [
                '<p class="x" style="background:url(javascript:alert(1))" onclick="alert(1)">t</p>',
                '<p>t</p>',
            ],
            'srcdoc（iframe 属性已被剪，这里只留文本）' => [
                '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe><b>k</b>',
                '<b>k</b>',
            ],
            'a 上的 formaction' => [
                '<a href="/ok" formaction="javascript:alert(1)">x</a>',
                '<a href="/ok">x</a>',
            ],
        ];
    }

    #[DataProvider('payloadProvider')]
    public function test_payload_is_neutralized_to_expected_output(string $input, ?string $expected): void
    {
        $clean = HtmlSanitizer::clean($input);

        if ($expected !== null) {
            $this->assertSame($expected, $clean, '输入：'.$input);
        }
        $this->assertFalse(
            HtmlSanitizer::containsExecutable($clean),
            '输出仍含可执行痕迹：'.$clean
        );
    }

    /** 定点性质：清洗结果再洗一次必须一字不差（否则说明存在二次解析漂移） */
    public function test_clean_is_idempotent_over_the_whole_battery(): void
    {
        foreach (self::payloadProvider() as $title => [$input, $expected]) {
            $once = HtmlSanitizer::clean($input);
            $this->assertSame($once, HtmlSanitizer::clean($once), '未收敛于：'.$title);
            if ($expected !== null) {
                $this->assertSame($expected, HtmlSanitizer::clean($once));
            }
        }
    }

    /** 输出本身即"干净输入"时不应被再次改写 */
    public function test_is_clean_reports_true_for_already_clean_output(): void
    {
        $clean = HtmlSanitizer::clean('<p>正文</p><a href="https://a.test/x">链</a>');

        $this->assertTrue(HtmlSanitizer::isClean($clean));
    }

    /** 正常文档必须活着：白名单太狠以至于写不出文档，等于功能没做 */
    public function test_legitimate_document_survives(): void
    {
        $html = <<<'HTML'
<h2>开账步骤</h2>
<p>先做<strong>期初库存</strong>，再看<em>余额</em>。换行<br>之后继续。</p>
<ul><li>第一步</li><li>第二步</li></ul>
<ol><li>甲</li></ol>
<blockquote>提示</blockquote>
<pre><code>amount = qty * price</code></pre>
<table><thead><tr><th>科目</th><th>金额</th></tr></thead><tbody>
<tr><td colspan="2">合计</td><td rowspan="2">100</td></tr></tbody></table>
<p><a href="https://docs.test/guide">手册</a> / <a href="mailto:a@b.test">邮箱</a> / <a href="#anchor">锚点</a></p>
<p><img src="/storage/img/a.png" alt="流程图" title="步骤图" width="600" height="300"></p>
HTML;

        $clean = HtmlSanitizer::clean($html);

        foreach (['<h2>开账步骤</h2>', '<strong>期初库存</strong>', '<br>', '<blockquote>提示</blockquote>',
            '<a href="https://docs.test/guide">手册</a>', '<a href="mailto:a@b.test">邮箱</a>', '<a href="#anchor">锚点</a>',
            'colspan="2"', 'rowspan="2"', 'alt="流程图"', 'width="600"', '<pre><code>'] as $needle) {
            $this->assertStringContainsString($needle, $clean, '合法结构被误删：'.$needle);
        }
        $this->assertSame($clean, HtmlSanitizer::clean($clean));
    }

    /** 相对路径 / 锚点 / 邮件这些"没有协议"的链接是文档里最常见的，不能一刀切 */
    public function test_relative_and_anchor_links_are_kept(): void
    {
        $clean = HtmlSanitizer::clean('<a href="/docs/a">a</a><a href="?page=2">b</a><a href="sub/x.html">c</a>');

        $this->assertStringContainsString('href="/docs/a"', $clean);
        $this->assertStringContainsString('href="?page=2"', $clean);
        $this->assertStringContainsString('href="sub/x.html"', $clean);
    }

    /** 内联 base64 图片只放行真图片类型：data:text/html 与 data:image/svg+xml 都要拒 */
    public function test_data_uri_only_allowed_for_raster_images(): void
    {
        $png = '<p><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUg==" alt="x"></p>';
        $this->assertStringContainsString('data:image/png;base64,', HtmlSanitizer::clean($png));

        foreach (['data:text/html;base64,PHNjcmlwdD4=', 'data:image/svg+xml;base64,PHN2Zz4='] as $bad) {
            $clean = HtmlSanitizer::clean('<p><img src="'.$bad.'" alt="x"></p>');
            $this->assertStringNotContainsString('data:', $clean);
        }
    }

    /** 白名单外的未知标签要"脱壳留肉"：内容不能丢，只是不再带那个标签 */
    public function test_unknown_tag_is_unwrapped_but_text_is_kept(): void
    {
        $this->assertSame('内容', HtmlSanitizer::clean('<marquee>内容</marquee>'));
        $this->assertSame('<p>前<b>中</b>后</p>', HtmlSanitizer::clean('<p>前<font color="red"><b>中</b></font>后</p>'));
        // span 本身在白名单里（富文本编辑器到处在用它做着色容器），
        // 所以这里只该掉属性、不该掉标签，否则编辑器存进来的内容会一路塌平
        $this->assertSame(
            '<p>前<span><b>中</b></span>后</p>',
            HtmlSanitizer::clean('<p>前<span style="x"><b>中</b></span>后</p>')
        );
    }

    /** 纯文本派生列：块级边界要还原成空格，否则搜索与摘要会粘连 */
    public function test_plain_text_inserts_block_boundaries_and_drops_script_text(): void
    {
        $html = '<p>开账</p><p>说明</p><script>var secret = 1</script><div>末尾</div>';

        $this->assertSame('开账 说明 末尾', HtmlSanitizer::plainText($html));
    }

    /** 空输入不该产生 <p><br></p> 之类的噪音 */
    public function test_empty_input_yields_empty_string(): void
    {
        foreach ([null, '', '   ', "\n\t"] as $input) {
            $this->assertSame('', HtmlSanitizer::clean($input));
            $this->assertSame('', HtmlSanitizer::plainText($input));
        }
    }

    /**
     * 超长输入截断成转义纯文本，而不是抛异常把保存打成 500，
     * 也绝不能"因为处理不了就原样返回"。
     */
    public function test_oversize_input_is_truncated_not_passed_through(): void
    {
        // 9 字 × 30000 = 270000 > MAX_INPUT_LENGTH(200000)；
        // 倍数是按这个算式挑的，写小了会静默走正常清洗分支、这条测试就等于没跑
        $input = str_repeat('很长的一段说明文字', 30000).'<script>alert(1)</script>';
        $this->assertGreaterThan(HtmlSanitizer::MAX_INPUT_LENGTH, mb_strlen($input));

        $clean = HtmlSanitizer::clean($input);

        $this->assertLessThanOrEqual(HtmlSanitizer::MAX_INPUT_LENGTH + 100, mb_strlen($clean));
        $this->assertFalse(HtmlSanitizer::containsExecutable($clean));
        $this->assertStringNotContainsString('<script', $clean);
    }

    /** 非 UTF-8 的畸形字节不能让解析器整个崩掉（解析失败也要退化成转义文本） */
    public function test_binary_garbage_does_not_escape_as_markup(): void
    {
        $clean = HtmlSanitizer::clean("\xC3\x28<script>alert(1)</script>尾部");

        $this->assertFalse(HtmlSanitizer::containsExecutable($clean));
        $this->assertStringNotContainsString('<script', strtolower($clean));
    }
}
