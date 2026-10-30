<?php

namespace Modules\Archive\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Archive\Models\HelpDoc;
use Modules\Archive\Services\HelpDocService;
use Modules\Archive\Support\HtmlSanitizer;

/**
 * 帮助中心（F6 块3）。
 *
 * 权限口径与本合同前几批一致：auth.check:admin 只校验登录，
 * 细粒度按钮权限由已登记的权限节点 + 前端 v-permission 控制
 * （`auth.check:admin,data:help:view` 会把权限点当第二个 guard 参数解析成 401，
 * 所以不写在这里）。
 *
 * 富文本安全边界只有一处：HtmlSanitizer。控制器不在这之外再加正则黑名单——
 * 两层各写一套过滤规则，最后一定会出现"A 层认为安全、B 层认为安全、
 * 拼起来不安全"的分歧，浏览器拼出来的 DOM 只认拼接结果。
 * 校验只做长度与类型（见 validateData），内容形态一律交给 sanitizer。
 */
class HelpDocController extends Controller
{
    use ResponseTrait;

    public function __construct(private readonly HelpDocService $service) {}

    public function index(Request $request)
    {
        return $this->paginated($this->service->search($this->filters($request)));
    }

    /** 分组导航（名称 + 文档数） */
    public function categories()
    {
        return $this->success($this->service->categories());
    }

    public function show($id)
    {
        $doc = HelpDoc::find($id);
        if (! $doc) {
            return $this->notFound('文档不存在');
        }

        return $this->success($doc);
    }

    /**
     * 阅读数 +1。
     *
     * 单独开一个接口而不是塞在 show 里：管理端编辑时也要 show 同一篇文档，
     * 混在一起会让"阅读数"变成"打开次数"，编辑十次就 +10。
     */
    public function read($id)
    {
        $doc = HelpDoc::find($id);
        if (! $doc) {
            return $this->notFound('文档不存在');
        }
        $this->service->incrementViews($doc);

        return $this->success(['views' => (int) $doc->fresh()->views]);
    }

    /**
     * 预览：把正文净化后原样回显，不落库。
     *
     * 前端「预览」页签不能直接把 textarea 的原始串 v-html 出去：
     * 一是管理员贴进来的脚本会在自己的登录态里跑起来（自 XSS，管理员身份 = 提权），
     * 二是预览会渲染出"存进去之后根本不会存在"的标签，所见非所得。
     * 也不在前端另写一套过滤规则——过滤规则有两套就一定会出现分歧，
     * 浏览器只认最终拼出来的 DOM。所以预览借用同一个 sanitizer。
     */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'content' => 'nullable|string|max:'.HtmlSanitizer::MAX_INPUT_LENGTH,
        ], [], ['content' => '正文']);

        $raw = trim((string) ($data['content'] ?? ''));
        $content = HtmlSanitizer::clean($raw);

        // 提示口径用"原文里有没有可执行痕迹"，不用"输出是否等于输入"：
        // 后者对 <div class="x"> 这种无害写法也会为真，天天喊"被过滤了"就等于没喊
        return $this->success([
            'content' => $content,
            'content_text' => HtmlSanitizer::plainText($content),
            'had_executable' => HtmlSanitizer::containsExecutable($raw),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        if ($conflict = $this->slugConflict($data['slug'] ?? null)) {
            return $conflict;
        }

        $doc = $this->service->create($data, $this->uid(), $this->uname());

        return $this->created($doc, '已创建');
    }

    public function update(Request $request, $id)
    {
        $doc = HelpDoc::find($id);
        if (! $doc) {
            return $this->notFound('文档不存在');
        }
        $data = $this->validateData($request);
        if ($conflict = $this->slugConflict($data['slug'] ?? null, $doc->id)) {
            return $conflict;
        }

        return $this->success($this->service->update($doc, $data, $this->uid(), $this->uname()), '已更新');
    }

    public function publish($id)
    {
        $doc = HelpDoc::find($id);
        if (! $doc) {
            return $this->notFound('文档不存在');
        }
        if (HtmlSanitizer::plainText($doc->content) === '') {
            return $this->error('正文为空，不能发布', 422);
        }

        return $this->success($this->service->publish($doc), '已发布');
    }

    public function withdraw($id)
    {
        $doc = HelpDoc::find($id);
        if (! $doc) {
            return $this->notFound('文档不存在');
        }

        return $this->success($this->service->withdraw($doc), '已撤回为草稿');
    }

    public function destroy($id)
    {
        $doc = HelpDoc::find($id);
        if (! $doc) {
            return $this->notFound('文档不存在');
        }
        $doc->delete();

        return $this->success(null, '已删除');
    }

    private function filters(Request $request): array
    {
        return [
            'keyword' => $request->input('keyword'),
            'category' => $request->input('category'),
            'status' => $request->input('status'),
            'page_size' => $request->integer('page_size', 30),
        ];
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:150',
            'slug' => ['nullable', 'string', 'max:150', 'regex:/^[a-z0-9\-]+$/'],
            'category' => ['required', 'string', 'max:50'],
            'summary' => 'nullable|string|max:255',
            // 上限与 HtmlSanitizer::MAX_INPUT_LENGTH 对齐：超长部分 sanitizer 会截断，
            // 静默截断比明确报错坏得多，所以在这里先拒
            'content' => 'nullable|string|max:'.HtmlSanitizer::MAX_INPUT_LENGTH,
            'version' => 'nullable|string|max:20',
            'sort' => 'nullable|integer|min:0',
            'is_top' => 'nullable|boolean',
            'status' => ['nullable', Rule::in([HelpDoc::STATUS_DRAFT, HelpDoc::STATUS_PUBLISHED])],
        ], [
            'slug.regex' => '别名只能包含小写字母、数字和连字符',
            'content.max' => '正文过长，请拆成多篇文档',
        ], [
            'title' => '标题',
            'slug' => '别名',
            'category' => '分组',
            'content' => '正文',
        ]);
    }

    /**
     * 显式传了 slug 且已被占用 -> 422。
     *
     * 只在**用户手填**时判冲突；留空由 Service 从标题生成并自动加后缀，
     * 那种情况下报"别名重复"用户无从下手。
     */
    private function slugConflict(?string $slug, ?int $ignoreId = null)
    {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return null;
        }
        $taken = HelpDoc::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        return $taken ? $this->error('别名已被占用，请换一个', 422) : null;
    }

    private function uid(): ?int
    {
        $user = auth('admin')->user();

        return $user?->id ? (int) $user->id : null;
    }

    private function uname(): ?string
    {
        $user = auth('admin')->user();
        if (! $user) {
            return null;
        }

        return $user->real_name ?: ($user->username ?? null);
    }
}
