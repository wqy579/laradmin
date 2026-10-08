<?php

namespace Modules\Office\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 模块七：公司公告
 *
 * 卡片式列表（不是表格）：置顶公告永远在最前，其余按发布时间倒序。
 * 摘要在后端截断生成：正文是富文本 HTML，前端 strip 标签做摘要会有 XSS 风险，
 * 而且每页几十条在浏览器里解析 HTML 纯属浪费，服务端做一次更干净。
 *
 * 发布范围 all / department / user 决定谁能看到；编辑与删除只放开给作者本人和超管，
 * 避免任何人都能改别人发的制度类公告。
 */
class NoticeController extends Controller
{
    /** 公告类型（列表筛选与详情展示共用同一份取值） */
    private const TYPE_COLORS = [
        'notice' => '通知',
        'rule' => '制度',
        'activity' => '活动',
        'other' => '其他',
    ];

    public function index(Request $request): JsonResponse
    {
        $userId = (int) auth('admin')->id();
        $keyword = trim((string) $request->input('keyword', ''));
        $type = trim((string) $request->input('type', ''));
        $pageSize = min(100, max(1, (int) $request->input('page_size', 20)));

        $query = DB::table('notices')->where(function ($q) use ($userId) {
            $this->scopeFilter($q, $userId);
        });

        if ($type !== '' && array_key_exists($type, self::TYPE_COLORS)) {
            $query->where('type', $type);
        }

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('title', 'like', "%{$keyword}%")->orWhere('content', 'like', "%{$keyword}%");
            });
        }

        $paginator = $query->orderByDesc('is_top')->orderByDesc('created_at')->paginate($pageSize);

        $list = collect($paginator->items())->map(function ($notice) use ($userId) {
            $notice->summary = $this->summary($notice->content);
            $notice->attachment_count = $this->attachmentCount($notice->attachments);
            $notice->can_edit = $this->canEdit($notice, $userId);
            // 编辑表单要回填可见范围，JSON 串在前端会变成一个无法匹配的字符串。
            // 这里统一解成数组，前端 el-select multiple 直接吃。
            $notice->scope_ids = $this->jsonToArray($notice->scope_ids);
            $notice->attachments = $this->jsonToArray($notice->attachments);
            // 列表不需要整篇正文，回传会明显拖慢列表
            unset($notice->content);

            return $notice;
        })->all();

        return $this->success([
            'list' => $list,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'types' => $this->types(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'type' => 'nullable|in:notice,rule,activity,other',
            'content' => 'nullable|string',
            'is_top' => 'nullable|boolean',
            'scope' => 'nullable|in:all,department,user',
            'scope_ids' => 'nullable|array',
            'scope_ids.*' => 'integer',
            'attachments' => 'nullable|array',
        ]);

        $userId = (int) auth('admin')->id();
        $userName = DB::table('auth_user')->where('id', $userId)->value('real_name');

        $id = DB::table('notices')->insertGetId([
            'title' => $validated['title'],
            'type' => $validated['type'] ?? 'notice',
            'content' => $validated['content'] ?? '',
            'is_top' => (bool) ($validated['is_top'] ?? false),
            'scope' => $validated['scope'] ?? 'all',
            'scope_ids' => json_encode($validated['scope_ids'] ?? [], JSON_UNESCAPED_UNICODE),
            'attachments' => json_encode($validated['attachments'] ?? [], JSON_UNESCAPED_UNICODE),
            'author_id' => $userId,
            'author_name' => $userName,
            'view_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->created(['id' => $id], '公告已发布');
    }

    public function show(int $notice): JsonResponse
    {
        $userId = (int) auth('admin')->id();

        $row = DB::table('notices')->where('id', $notice)->first();
        if (! $row) {
            return $this->notFound('公告不存在');
        }

        // 阅读量：点开详情才计，列表刷新不计数
        DB::table('notices')->where('id', $notice)->increment('view_count');
        $row->view_count += 1;
        $row->can_edit = $this->canEdit($row, $userId);
        $row->scope_ids = $this->jsonToArray($row->scope_ids);
        $row->attachments = $this->jsonToArray($row->attachments);
        $row->type_label = self::TYPE_COLORS[$row->type] ?? '其他';

        return $this->success($row);
    }

    public function update(Request $request, int $notice): JsonResponse
    {
        $userId = (int) auth('admin')->id();
        $row = DB::table('notices')->where('id', $notice)->first();

        if (! $row) {
            return $this->notFound('公告不存在');
        }
        if (! $this->canEdit($row, $userId)) {
            return $this->forbidden('只能编辑自己发布的公告');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'type' => 'nullable|in:notice,rule,activity,other',
            'content' => 'nullable|string',
            'is_top' => 'nullable|boolean',
            'scope' => 'nullable|in:all,department,user',
            'scope_ids' => 'nullable|array',
            'scope_ids.*' => 'integer',
            'attachments' => 'nullable|array',
        ]);

        DB::table('notices')->where('id', $notice)->update([
            'title' => $validated['title'],
            'type' => $validated['type'] ?? 'notice',
            'content' => $validated['content'] ?? '',
            'is_top' => (bool) ($validated['is_top'] ?? false),
            'scope' => $validated['scope'] ?? 'all',
            'scope_ids' => json_encode($validated['scope_ids'] ?? [], JSON_UNESCAPED_UNICODE),
            'attachments' => json_encode($validated['attachments'] ?? [], JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        return $this->success(['id' => $notice], '公告已更新');
    }

    public function destroy(int $notice): JsonResponse
    {
        $userId = (int) auth('admin')->id();
        $row = DB::table('notices')->where('id', $notice)->first();

        if (! $row) {
            return $this->notFound('公告不存在');
        }
        if (! $this->canEdit($row, $userId)) {
            return $this->forbidden('只能删除自己发布的公告');
        }

        DB::table('notices')->where('id', $notice)->delete();

        return $this->success(null, '公告已删除');
    }

    /** 可见范围过滤：全员 / 指定部门命中我的部门 / 指定人员命中我 */
    private function scopeFilter($query, int $userId): void
    {
        $departmentId = (int) DB::table('auth_user')->where('id', $userId)->value('department_id');

        $query->where('scope', 'all');

        if ($departmentId) {
            $query->orWhere(function ($q) use ($departmentId) {
                $q->where('scope', 'department')->whereJsonContains('scope_ids', $departmentId);
            });
        }

        $query->orWhere(function ($q) use ($userId) {
            $q->where('scope', 'user')->whereJsonContains('scope_ids', $userId);
        });
    }

    private function canEdit(object $notice, int $userId): bool
    {
        if ((int) $notice->author_id === $userId) {
            return true;
        }

        return DB::table('auth_user_role as ur')
            ->join('auth_role as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)
            ->where('r.code', 'super_admin')
            ->exists();
    }

    /** 富文本转纯文本摘要，最多 120 字 */
    private function summary(?string $content): string
    {
        $text = trim(strip_tags(html_entity_decode((string) $content, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $text = preg_replace('/\s+/u', ' ', $text) ?: '';

        return mb_substr($text, 0, 120);
    }

    private function attachmentCount(mixed $attachments): int
    {
        if (empty($attachments)) {
            return 0;
        }
        $decoded = json_decode((string) $attachments, true);

        return is_array($decoded) ? count($decoded) : 0;
    }

    private function types(): array
    {
        return [
            ['value' => 'notice', 'label' => '通知'],
            ['value' => 'rule', 'label' => '制度'],
            ['value' => 'activity', 'label' => '活动'],
            ['value' => 'other', 'label' => '其他'],
        ];
    }

    /**
     * scope_ids / attachments 在库里是 JSON 串，取出来要解成数组。
     * 已经在数组形态（或空值）时原样返回，避免重复解码出错。
     */
    private function jsonToArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || $value === '' || $value === '[]') {
            return [];
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
