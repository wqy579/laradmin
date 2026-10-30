<?php

namespace Modules\Archive\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;

/**
 * 帮助中心富文本的 XSS 过滤器（F6 块3 的安全验收项）。
 *
 * 为什么自己写：仓库不能加依赖（composer.lock 与 content-hash 是 CI 硬门禁，
 * mews/purifier / ezyang-htmlpurifier 都没装，前端也没有 DOMPurify），
 * 而前端要用 v-html 渲染帮助正文，服务端这一层就是唯一的边界，不能省。
 *
 * 设计要点（deny-by-default）：
 *  1. **白名单**解析：DOMDocument 解析后，只保留 ALLOWED_TAGS 里的标签，
 *     其余一律处理。脚本类标签（script/style/iframe/...）连同**整棵子树**删除——
 *     它们的文本内容本身就是可执行代码（`<style>` 里就是 CSS），不能只脱壳留文字，
 *     否则正文里会冒出一段 `@import url(javascript:...)` 的噪音。
 *     其它非白名单标签（font/div 之外的语义容器、textarea 等）只**脱壳**保留文字：
 *     帮助文档里 `<b>`、`<font>` 这类写法很常见，直接删文字会吃掉正文。
 *  2. **属性白名单**按标签维度声明，没有列进去的一个不留：所有 `on*`、
 *     `style`、`class`、`data-*`、`srcdoc`、`formaction`、`xlink:href` 全部丢弃。
 *     class 也丢——编辑器/复制粘贴带进来的 class 名我们完全不控制，
 *     留着只会有两个结果：样式意外生效，或者成为属性注入面。收益不值这个风险。
 *  3. URL 先归一再判协议。归一顺序：解 HTML 实体 → 删掉所有空白与
 *     ASCII 控制字符（<0x20 与 0x7f）→ 小写 → 再匹配协议。
 *     因为 `jav&#x09;ascript:` / `&#106;avascript:` / ` java\0script:`
 *     这些写法在浏览器里都会还原成 `javascript:`，只有先归一再判才拦得住。
 *  4. **定点迭代**（见 sanitizeNode 后的 clean 方法）：mXSS 类载荷
 *     （`<noscript><p title="</noscript><img src=x onerror=...>">`）的特征是
 *     "A 解析器看到的是属性字符串、B 解析器看到的是标签"。我们过滤完再序列化，
 *     序列化结果可能就是一段新的危险输入。所以把输出再喂回自己洗一遍，
 *     直到输出不再变化（定点）或到达次数上限。这是唯一能覆盖
 *     "两个解析器不一致"这类漏洞的通用手段，正则或一次性 strip_tags 都不行。
 *
 * 边界（第二道防线不是第三个过滤器）：`content` 只由本类清洗后入库，
 * 前端 help 页只渲染 `GET data/help/{id}` 返回的 content；不接收 URL 参数、
 * 不渲染本地拼出来的 HTML。加不了客户端 DOMPurify（没依赖），
 * 所以边界靠"只有一个写入路径 + 只有一个渲染源"来保证，而不是再叠一层正则。
 */
class HtmlSanitizer
{
    /** 允许的标签。刻意不含 a 的 target、img 的 srcset 等扩展能力，够用就行。 */
    public const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'span', 'div',
        'strong', 'b', 'em', 'i', 'u', 's',
        'ul', 'ol', 'li',
        'h1', 'h2', 'h3', 'h4', 'h5',
        'blockquote', 'pre', 'code',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th',
        'a', 'img',
    ];

    /**
     * 连文字一起删掉的标签：内容本身就是可执行/可渲染代码，
     * 脱壳保留文字反而会把载荷显示成"看起来无害的一段代码"。
     */
    public const KILL_TAGS = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed',
        'applet', 'template', 'noscript', 'svg', 'math', 'canvas', 'audio',
        'video', 'source', 'track', 'base', 'meta', 'link', 'head', 'title',
        'form', 'input', 'button', 'select', 'option', 'textarea', 'keygen',
    ];

    /** 标签 => 允许的属性（大小写不敏感，匹配前统一小写） */
    public const ALLOWED_ATTRS = [
        'a' => ['href'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan'],
    ];

    /** 单条正文的字符上限：help 文档是人写的说明书，不是网盘，超限直接拒绝而不是硬解析 */
    public const MAX_INPUT_LENGTH = 200_000;

    /** 定点迭代上限。实测正常文档 2 轮就收敛，6 次是留给畸形嵌套的余量。 */
    private const MAX_PASSES = 6;

    /** 归一后允许出现的协议（href） */
    private const ALLOWED_SCHEMES = ['http:', 'https:', 'mailto:', 'tel:'];

    /** 图片额外允许 data:image/{png,jpeg,gif,webp};base64 —— 粘贴截图是刚需，且它无法执行脚本 */
    private const ALLOWED_IMG_DATA = '#^data:image/(png|jpe?g|gif|webp);base64,[a-z0-9+/=]+$#';

    /** 真正会执行的东西，用来自检输出（测试与防御性兜底共用） */
    private const DANGEROUS_PATTERN = '#(<\s*/?\s*(script|iframe|object|embed|style|svg|math|form|base|meta|link|textarea|noscript|template|input|button)\b)|(\son[a-z]{2,24}\s*=)|(javascript\s*:)|(vbscript\s*:)|(data\s*:\s*text/html)|(\ssrcdoc\s*=)|(\sformaction\s*=)|(xlink\s*:)#i';

    /**
     * 清洗一段 HTML，返回可安全交给 v-html 的字符串。
     *
     * 超长输入返回**已清洗的截断文本**（转义后的纯文本）而不是抛异常：
     * 帮助文档超限属于内容问题，不该让保存动作 500；控制器另有 422 校验兜住。
     */
    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }
        if (mb_strlen($html) > self::MAX_INPUT_LENGTH) {
            return nl2br(htmlspecialchars(mb_substr(self::plainText($html), 0, self::MAX_INPUT_LENGTH), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        }

        $current = $html;
        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $next = self::sanitizeOnce($current);
            if ($next === $current) {
                return $current;
            }
            $current = $next;
        }

        // 到上限还没收敛：不再信任结构，退化为纯文本。
        // 走不到这里（正常文档第 2 轮就定点），留着是为了"永不输出可疑结构"这个不变量。
        return self::escapeAll(self::plainText($current));
    }

    /** 从 HTML 派生纯文本，用于 content_text（列表摘要 + 关键词搜索） */
    public static function plainText(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }

        // 先走一遍清洗，保证脚本类子树不参与取词，再取 textContent
        $doc = self::parse(self::sanitizeOnce($html), 'f6-'.bin2hex(random_bytes(8)));
        if (! $doc) {
            return '';
        }

        $text = '';
        foreach ($doc->documentElement->childNodes as $node) {
            $text .= self::collectText($node);
        }

        // 块级边界还原成空格，否则 <p>a</p><p>b</p> 会粘成 "ab" 影响搜索
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /** 输入是否已经是"干净"的（用于自检/测试断言） */
    public static function isClean(?string $html): bool
    {
        return self::clean($html) === trim((string) $html);
    }

    /** 输出里是否还残留可执行痕迹（测试用；也是 clean() 的兜底复检依据） */
    public static function containsExecutable(string $html): bool
    {
        return (bool) preg_match(self::DANGEROUS_PATTERN, $html);
    }

    /**
     * 单轮清洗：解析 → 白名单过滤 → 序列化片段。
     */
    private static function sanitizeOnce(string $html): string
    {
        // 标记属性带随机后缀：固定写法会让攻击者能自己塞一个同名标记，
        // 从而把真正的正文节点排除在清洗范围之外
        $token = 'f6-'.bin2hex(random_bytes(8));
        $doc = self::parse($html, $token);
        if (! $doc) {
            return self::escapeAll($html);
        }

        $body = self::body($doc, $token);
        if (! $body) {
            return '';
        }
        self::filterChildren($doc, $body);

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * 解析成 DOM。
     *
     * 三处关键处理：
     *  - 剥掉输入自带的 `<?xml ...?>`：libxml 会按 XML 声明里的 encoding 解码，
     *    攻击者可以用 `<?xml encoding="UTF-16">` 之类把后续字节按另一种编码解释，
     *    从而绕过字符级过滤。
     *  - 我们自己的 `<?xml encoding="UTF-8">` 前缀是 libxml 在 HTML4 解析器里
     *    声明 UTF-8 的标准手法（不用已废弃的 mb_convert_encoding(..., 'HTML-ENTITIES')）。
     *  - 用 `<div>` 包一层再取回子节点：直接解析片段时 libxml 会补
     *    `<html><body>`，若直接序列化整个文档，`<body onload=...>` 这类载荷
     *    就可能带着属性回到输出里。
     */
    private static function parse(string $html, string $token): ?DOMDocument
    {
        // 去 BOM，否则它会变成片段里的第一个字符混进正文
        $html = preg_replace('/^\x{FEFF}/u', '', ltrim($html, "\xEF\xBB\xBF")) ?? $html;
        // 去掉任何 XML 声明（含被塞在中间的变种写法）
        $html = preg_replace('/<\?xml[^>?]*\?>?/i', '', $html) ?? $html;

        $payload = '<?xml encoding="UTF-8"?><div data-'.$token.'="">'.$html.'</div>';

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = false;
        $doc->preserveWhiteSpace = true;
        $ok = $doc->loadHTML($payload, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $ok || ! $doc->documentElement) {
            return null;
        }
        // 编码声明前缀可能被 libxml 当成处理指令或注释留下，序列化时会变成裸文本
        self::dropProcessingArtifacts($doc);

        return $doc;
    }

    private static function dropProcessingArtifacts(DOMDocument $doc): void
    {
        foreach (iterator_to_array($doc->childNodes) as $node) {
            if ($node instanceof \DOMProcessingInstruction || $node instanceof \DOMComment) {
                $doc->removeChild($node);
            }
        }
    }

    /** 定位我们的包裹 div（loadHTML 之后它在 body 里，层级取决于 libxml 版本） */
    private static function body(DOMDocument $doc, string $token): ?DOMElement
    {
        // 标记属性名是 'f6-'+hex，始终是合法 XML 名，可以直接写进 XPath 谓词
        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//*[@data-'.$token.']');
        $found = $nodes && $nodes->length > 0 ? $nodes->item(0) : null;
        if ($found instanceof DOMElement) {
            $found->removeAttribute('data-'.$token);

            return $found;
        }

        $bodies = $doc->getElementsByTagName('body');

        return $bodies->length > 0 ? $bodies->item(0) : null;
    }

    /** 处理一个节点的所有子节点（自顶向下，过滤属性后递归） */
    private static function filterChildren(DOMDocument $doc, DOMNode $parent): void
    {
        // 先快照：遍历中会删除/替换节点，实时 NodeList 会跳节点
        foreach (iterator_to_array($parent->childNodes) as $node) {
            self::filterNode($doc, $parent, $node);
        }
    }

    private static function filterNode(DOMDocument $doc, DOMNode $parent, DOMNode $node): void
    {
        if ($node instanceof DOMText) {
            return;
        }

        // 注释 / CDATA / 处理指令：一律删除。
        // 注释是 mXSS 的经典载体（条件注释、`<!--><script>`），帮助文档里也不需要。
        if (! ($node instanceof DOMElement)) {
            $parent->removeChild($node);

            return;
        }

        $tag = strtolower($node->nodeName);

        // 命名空间标签（svg:* / math:*）与危险标签：整棵子树删掉
        if (in_array($tag, self::KILL_TAGS, true) || $node->namespaceURI !== null) {
            $parent->removeChild($node);

            return;
        }

        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            // 非白名单：先递归洗子节点（子节点可能是 <img onerror>），再脱壳上提
            self::filterChildren($doc, $node);
            self::unwrap($parent, $node);

            return;
        }

        self::filterAttributes($node, $tag);
        self::filterChildren($doc, $node);
    }

    /** 只保留该标签白名单内的属性，URL 类属性还要过协议校验 */
    private static function filterAttributes(DOMElement $node, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRS[$tag] ?? [];

        foreach (iterator_to_array($node->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            if (! in_array($name, $allowed, true)) {
                $node->removeAttribute($attr->nodeName);

                continue;
            }
            $value = (string) $attr->nodeValue;
            if ($name === 'href' && ! self::isSafeUrl($value)) {
                // 链接不合格就整条去掉（不保留 href="" 这种可被再解析的残留）
                $node->removeAttribute('href');
            } elseif ($name === 'src' && ! self::isSafeImageSrc($value)) {
                $node->removeAttribute('src');
            } elseif (in_array($name, ['width', 'height', 'colspan', 'rowspan'], true)) {
                // 数值属性只留数字，避免 "100" 之外的写法（含表达式/实体）进入输出
                $digits = preg_replace('/\D/', '', $value) ?? '';
                if ($digits === '' || (int) $digits > 10000) {
                    $node->removeAttribute($name);
                } else {
                    $node->setAttribute($name, $digits);
                }
            } elseif (in_array($name, ['alt', 'title'], true)) {
                $node->setAttribute($name, trim(preg_replace('/\s+/u', ' ', $value) ?? ''));
            }
        }
    }

    /**
     * URL 归一 + 协议白名单。
     *
     * 归一必须**在判断之前**做完：浏览器解析 URL 时会忽略内嵌的 TAB/LF/CR 与
     * 控制字符，也会先解实体，所以 `jav&#x09;ascript:`、`&#106;avascript:`
     * 在浏览器眼里都等于 `javascript:`。先判后清等于没判。
     */
    private static function isSafeUrl(string $value): bool
    {
        $normalized = self::normalizeUrl($value);
        if ($normalized === '') {
            return false;
        }

        $probe = $normalized;
        // 协议相对 / 绝对路径 / 锚点：无协议，同源读取，允许
        if (str_starts_with($probe, '#') || str_starts_with($probe, '/') || str_starts_with($probe, '?')) {
            return true;
        }
        if (preg_match('#^[a-z0-9+.-]*:#', $probe)) {
            return in_array(self::schemeOf($probe), self::ALLOWED_SCHEMES, true);
        }

        // 无冒号的相对路径（./a.png、docs/intro.html）
        return ! str_contains($probe, ':');
    }

    private static function isSafeImageSrc(string $value): bool
    {
        $normalized = self::normalizeUrl($value);
        if ($normalized === '') {
            return false;
        }
        if (preg_match(self::ALLOWED_IMG_DATA, $normalized)) {
            return true;
        }

        return self::isSafeUrl($value);
    }

    private static function normalizeUrl(string $value): string
    {
        // 迭代解实体：`&amp;#106;avascript:` 解一次仍是实体写法，解到不动为止
        $decoded = $value;
        for ($i = 0; $i < 3; $i++) {
            $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }
        // NCR 数字实体（html_entity_decode 已覆盖大部分，这里兜 &#0; 这类被丢掉的写法）
        $decoded = preg_replace('/&#x?0*;?/i', '', $decoded) ?? $decoded;
        // 删掉所有空白与控制字符：包含 TAB/LF/CR、NUL、0x7f，以及 Unicode 空白
        $stripped = preg_replace('/[\s\x00-\x20\x7f\x{200B}-\x{200F}\x{FEFF}]/u', '', $decoded) ?? '';

        return mb_strtolower($stripped);
    }

    /** 取归一串里的协议（含冒号），无协议返回 null */
    private static function schemeOf(string $normalized): ?string
    {
        // 只认第一个冒号之前的协议名，且协议名不得含路径分隔符
        if (preg_match('#^([a-z0-9+.\-]+):#', $normalized, $m)) {
            return $m[1].':';
        }

        return null;
    }

    /** 把节点的孩子上提后删掉自己（脱壳），文本边界补空格避免粘连 */
    private static function unwrap(DOMNode $parent, DOMNode $node): void
    {
        $children = iterator_to_array($node->childNodes);
        $ref = $node;
        foreach ($children as $child) {
            $parent->insertBefore($child, $ref);
        }
        $parent->removeChild($node);
    }

    /** 递归取文本；script/style 这类子树已经被删掉，这里不会再取到代码 */
    private static function collectText(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $node->nodeValue ?? '';
        }
        if ($node instanceof DOMElement && in_array(strtolower($node->nodeName), ['br', 'hr'], true)) {
            return ' ';
        }

        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= self::collectText($child);
        }
        // 块级元素结束补空格
        if ($node instanceof DOMElement) {
            $tag = strtolower($node->nodeName);
            if (in_array($tag, ['p', 'div', 'li', 'tr', 'td', 'th', 'h1', 'h2', 'h3', 'h4', 'h5', 'pre', 'blockquote'], true)) {
                $text .= ' ';
            }
        }

        return $text;
    }

    private static function escapeAll(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
