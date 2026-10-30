<?php

namespace Modules\Archive\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Modules\Archive\Models\HelpDoc;
use Modules\Archive\Support\HtmlSanitizer;

/**
 * 帮助文档业务层（F6 块3）。
 *
 * 两条硬约束：
 *  1. content 只能以净化后的形态入库（HelpDoc::setContentAttribute 已兜底），
 *     content_text 始终由 content 派生，不接受外部传入——否则搜索能命中正文里没有的词。
 *  2. slug 全表唯一且含软删行（唯一索引不认软删），所以查重必须 withTrashed()，
 *     撞号时追加 -2/-3 而不是报错：中文标题转写后经常大量重合（如「开账说明」），
 *     让用户手改一个英文 slug 是没有意义的负担。
 */
class HelpDocService
{
    /** 分组候选：菜单/向导相关的一期分组，前端下拉直接用 */
    public const CATEGORIES = ['开账', '基础档案', '单据操作', '报表查询', '系统设置', '通用'];

    /** 列表（含草稿，管理页视角）。status=published 时只给已发布。 */
    public function search(array $filters): LengthAwarePaginator
    {
        $query = HelpDoc::query();

        if (! empty($filters['keyword'])) {
            $like = '%'.str_replace('%', '\%', $filters['keyword']).'%';
            // 只搜 title/summary/content_text：搜 content 会命中标签名与属性，
            // 比如搜 "code" 把所有带 <code> 的文档都捞出来
            $query->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('summary', 'like', $like)
                    ->orWhere('content_text', 'like', $like);
            });
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $pageSize = max(1, min(100, (int) ($filters['page_size'] ?? 30)));

        return $query
            ->orderByDesc('is_top')
            ->orderBy('sort')
            ->orderByDesc('id')
            ->select([
                'id', 'title', 'slug', 'category', 'summary', 'version', 'sort',
                'status', 'is_top', 'views', 'published_at', 'created_by_name',
                'updated_by_name', 'created_at', 'updated_at',
            ])
            ->paginate($pageSize);
    }

    /** 分组及各自文档数（前端左侧分组导航） */
    public function categories(): array
    {
        $counts = HelpDoc::query()
            ->groupBy('category')
            ->selectRaw('category, count(*) as cnt')
            ->pluck('cnt', 'category');

        $list = [];
        foreach (self::CATEGORIES as $name) {
            $list[] = ['name' => $name, 'count' => (int) ($counts[$name] ?? 0)];
        }
        // 有人手工填了候选外的分组时也不能在导航里消失
        foreach ($counts as $name => $cnt) {
            if (! in_array($name, self::CATEGORIES, true)) {
                $list[] = ['name' => $name, 'count' => (int) $cnt];
            }
        }

        return ['list' => $list, 'total' => (int) $counts->sum()];
    }

    public function create(array $data, ?int $uid = null, ?string $uname = null): HelpDoc
    {
        $payload = $this->compose($data);
        $payload['slug'] = $this->uniqueSlug($data['slug'] ?? null, $payload['title']);
        $payload['created_by'] = $uid;
        $payload['created_by_name'] = $uname;
        $payload['updated_by'] = $uid;
        $payload['updated_by_name'] = $uname;

        $doc = HelpDoc::create($payload);
        // slug 依赖 id 兜底（纯中文标题转写为空时），所以建完可能要回填一次
        if ($payload['slug'] === '') {
            $doc->slug = 'doc-'.base_convert((string) $doc->id, 10, 36);
            $doc->save();
        }

        return $this->ensureSummary($doc);
    }

    public function update(HelpDoc $doc, array $data, ?int $uid = null, ?string $uname = null): HelpDoc
    {
        $payload = $this->compose($data);

        // slug 的规则（按优先级）：
        //  1. 显式传了且与原值不同 -> 用它（控制器已排除自撞，这里只加 -N 避重）；
        //  2. 只改了标题没传 slug -> 从新标题重生成；
        //  3. 其余情况一律不动 slug —— 深链可能已经贴进工单/群里，改标题不该弄断链接。
        // 注意 2 里中文标题转写为空时**保留原 slug**：写空串会跟其他空 slug 行撞唯一索引。
        $slug = null;
        if (! empty($data['slug'])) {
            if ($data['slug'] !== $doc->slug) {
                $slug = $this->uniqueSlug($data['slug'], $payload['title'], $doc->id);
            }
        } elseif ($payload['title'] !== $doc->title) {
            $slug = $this->uniqueSlug(null, $payload['title'], $doc->id);
        }
        if ($slug !== null && $slug !== '') {
            $payload['slug'] = $slug;
        }
        $payload['updated_by'] = $uid;
        $payload['updated_by_name'] = $uname;

        $doc->update($payload);

        return $doc->fresh();
    }

    public function publish(HelpDoc $doc): HelpDoc
    {
        $doc->update([
            'status' => HelpDoc::STATUS_PUBLISHED,
            'published_at' => $doc->published_at ?: now(),
        ]);

        return $doc->fresh();
    }

    public function withdraw(HelpDoc $doc): HelpDoc
    {
        // published_at 保留：它是"第一次发布时间"，撤回再发布不该抹掉历史
        $doc->update(['status' => HelpDoc::STATUS_DRAFT]);

        return $doc->fresh();
    }

    public function incrementViews(HelpDoc $doc): void
    {
        // 单条 UPDATE 自增，避免"读-改-写"把并发阅读数写丢
        HelpDoc::where('id', $doc->id)->increment('views');
    }

    /**
     * 把请求字段整理成可入库的列。
     * content_text 一律由 content 重新派生（见类注释）；summary 留空时用正文前 80 字。
     */
    private function compose(array $data): array
    {
        $content = HtmlSanitizer::clean($data['content'] ?? null);
        $text = HtmlSanitizer::plainText($content);

        return [
            'title' => $data['title'],
            'category' => $data['category'] ?? '通用',
            'content' => $content,
            'content_text' => $text,
            'summary' => $this->resolveSummary($data['summary'] ?? null, $text),
            'version' => $data['version'] ?? null,
            'sort' => (int) ($data['sort'] ?? 0),
            'is_top' => ! empty($data['is_top']),
            'status' => ($data['status'] ?? HelpDoc::STATUS_DRAFT) === HelpDoc::STATUS_PUBLISHED
                ? HelpDoc::STATUS_PUBLISHED
                : HelpDoc::STATUS_DRAFT,
        ];
    }

    private function resolveSummary(?string $summary, string $text): ?string
    {
        $summary = trim((string) $summary);
        if ($summary !== '') {
            return Str::limit($summary, 255, '');
        }

        return $text === '' ? null : Str::limit($text, 120, '…');
    }

    /** 摘要依赖正文，创建/更新后统一回填一次 */
    private function ensureSummary(HelpDoc $doc): HelpDoc
    {
        if (! $doc) {
            return $doc;
        }
        $summary = trim((string) $doc->summary);
        if ($summary === '' && $doc->content_text) {
            $doc->update(['summary' => $this->resolveSummary(null, $doc->content_text)]);

            return $doc->fresh();
        }

        return $doc;
    }

    /**
     * 生成唯一 slug。
     *
     * - 显式传入优先（校验在控制器：只允许 [a-z0-9-]）；
     * - 否则从标题转写，中文转写为空时**返回空串**，由 create() 用 id 兜底
     *   （那时 id 还没生成，不在这里瞎猜一个随机值——随机 slug 会让深链没法预测）；
     * - 查重必须带 withTrashed()：slug 是唯一索引，软删行仍占位，
     *   不查软删就会在 create 时抛 SQL 唯一键冲突（500）而不是自动改名。
     */
    private function uniqueSlug(?string $slug, ?string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug((string) $slug);
        if ($base === '') {
            $base = Str::slug((string) $title);
        }
        if ($base === '') {
            return '';
        }

        $candidate = $base;
        // 上限 150 与列宽一致，留 10 个字符给 -N 后缀
        $candidate = Str::limit($candidate, 140, '');
        for ($i = 2; $this->slugTaken($candidate, $ignoreId); $i++) {
            $candidate = Str::limit($base, 130, '').'-'.$i;
        }

        return $candidate;
    }

    private function slugTaken(string $slug, ?int $ignoreId): bool
    {
        return HelpDoc::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }
}
