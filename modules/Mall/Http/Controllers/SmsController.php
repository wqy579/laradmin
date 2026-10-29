<?php

namespace Modules\Mall\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Auth\Models\User as AdminUser;
use Modules\Mall\Models\SmsRecord;
use Modules\Mall\Models\SmsTemplate;

/**
 * F5 短信 —— 模板 CRUD + 变量实时预览 + 发送记录。
 *
 * 模板体用 {变量名} 占位，variables 存 {变量名: 示例值}，预览即替换。
 * 发送：变量实际值传入 → render 替换 → 落 sms_records（pending）→
 * 模拟网关异步置 success（无真实网关配置，状态机与记录结构真实，
 * 供后续接入短信服务商时替换发送内核）。
 */
class SmsController extends Controller
{
    use ResponseTrait;

    /** 模板列表 */
    public function index(Request $request)
    {
        $query = SmsTemplate::query()
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $kw = $request->keyword;
                $q->where(fn ($q) => $q->where('code', 'like', '%'.$kw.'%')
                    ->orWhere('name', 'like', '%'.$kw.'%')
                    ->orWhere('content', 'like', '%'.$kw.'%'));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', (int) $request->status));

        $rows = $query->orderByDesc('id')->paginate($request->integer('page_size', 20));

        $items = collect($rows->items())->map(fn (SmsTemplate $t) => $this->templateCard($t))->all();

        return $this->success([
            'list' => $items,
            'total' => $rows->total(),
            'page' => $rows->currentPage(),
            'page_size' => $rows->perPage(),
            'last_page' => $rows->lastPage(),
        ]);
    }

    /** 新建模板 */
    public function store(Request $request)
    {
        $validated = $this->validateTemplate($request);

        if (SmsTemplate::where('code', $validated['code'])->exists()) {
            return $this->error('模板编码已存在', 422);
        }

        $template = SmsTemplate::create([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'content' => $validated['content'],
            'variables' => $validated['variables'] ?? null,
            'remark' => $validated['remark'] ?? null,
            'status' => $validated['status'] ?? 1,
            'created_by' => auth('admin')->id(),
        ]);

        return $this->created($this->templateCard($template), '创建成功');
    }

    /** 更新模板 */
    public function update(Request $request, int $id)
    {
        $template = SmsTemplate::findOrFail($id);
        $validated = $this->validateTemplate($request);

        if (SmsTemplate::where('code', $validated['code'])->where('id', '<>', $id)->exists()) {
            return $this->error('模板编码已存在', 422);
        }

        $template->update([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'content' => $validated['content'],
            'variables' => $validated['variables'] ?? null,
            'remark' => $validated['remark'] ?? null,
            'status' => $validated['status'] ?? $template->status,
        ]);

        return $this->success($this->templateCard($template->fresh()), '保存成功');
    }

    /** 删除模板 */
    public function destroy(int $id)
    {
        $template = SmsTemplate::findOrFail($id);
        $template->delete();

        return $this->success(null, '已删除');
    }

    /** 启用/停用切换 */
    public function toggle(Request $request, int $id)
    {
        $template = SmsTemplate::findOrFail($id);
        $status = (int) $request->input('status', $template->status === 1 ? 0 : 1);
        $template->update(['status' => $status]);

        return $this->success($template->only(['id', 'status']), $status === 1 ? '已启用' : '已停用');
    }

    /** 变量实时预览：{客户名} → 示例值（前端每次输入即时调用） */
    public function preview(Request $request)
    {
        $validated = $request->validate([
            'content' => 'required|string|max:500',
            'variables' => 'nullable|array',
        ]);

        $map = $validated['variables'] ?? [];
        $preview = (new SmsTemplate(['content' => $validated['content'], 'variables' => $map]))->preview();

        // 变量清单（供前端补填示例值）
        if (! preg_match_all('/\{([^{}]+)\}/u', $validated['content'], $m)) {
            $variables = [];
        } else {
            $variables = array_values(array_unique($m[1]));
        }

        return $this->success([
            'content' => $validated['content'],
            'preview' => $preview,
            'variables' => $variables,
            'examples' => $map,
        ]);
    }

    /** 发送记录列表 */
    public function records(Request $request)
    {
        $query = SmsRecord::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $kw = $request->keyword;
                $q->where(fn ($q) => $q->where('send_no', 'like', '%'.$kw.'%')
                    ->orWhere('mobile', 'like', '%'.$kw.'%')
                    ->orWhere('receiver_name', 'like', '%'.$kw.'%'));
            })
            ->when($request->filled('start_date'), fn ($q) => $q->whereDate('created_at', '>=', $request->start_date))
            ->when($request->filled('end_date'), fn ($q) => $q->whereDate('created_at', '<=', $request->end_date));

        $rows = $query->orderByDesc('id')->paginate($request->integer('page_size', 20));

        $items = collect($rows->items())->map(fn (SmsRecord $r) => $this->recordCard($r))->all();

        return $this->success([
            'list' => $items,
            'total' => $rows->total(),
            'page' => $rows->currentPage(),
            'page_size' => $rows->perPage(),
            'last_page' => $rows->lastPage(),
        ]);
    }

    /** 发送：按模板渲染并落记录（异步置成功） */
    public function send(Request $request)
    {
        $validated = $request->validate([
            'template_id' => 'required|integer|exists:sms_templates,id',
            'mobile' => 'required|string|max:30',
            'receiver_name' => 'nullable|string|max:50',
            'variables' => 'nullable|array',
        ]);

        $template = SmsTemplate::findOrFail((int) $validated['template_id']);
        if ($template->status !== 1) {
            return $this->error('该模板已停用', 422);
        }

        try {
            $content = $template->render($validated['variables'] ?? []);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $operator = AdminUser::find(auth('admin')->id());
        $record = SmsRecord::create([
            'send_no' => $this->nextSendNo(),
            'template_id' => $template->id,
            'template_code' => $template->code,
            'template_name' => $template->name,
            'mobile' => $validated['mobile'],
            'receiver_name' => $validated['receiver_name'] ?? null,
            'content' => $content,
            'variables' => $validated['variables'] ?? null,
            'status' => 'pending',
            'created_by' => auth('admin')->id(),
            'creator_name' => $operator?->real_name ?: $operator?->username,
        ]);

        // 模拟网关：无真实服务商配置，置 pending 后由调度置成功。
        // 状态机真实（pending/success/failed），接入真实网关时替换此处。
        $record->update(['status' => 'success', 'sent_at' => now()]);

        return $this->created($this->recordCard($record->fresh()), '发送成功');
    }

    /** 失败重发：复制原记录重新走发送 */
    public function resend(Request $request, int $id)
    {
        $original = SmsRecord::findOrFail($id);
        $template = SmsTemplate::find($original->template_id);
        if (! $template || $template->status !== 1) {
            return $this->error('模板不存在或已停用，无法重发', 422);
        }

        try {
            $content = $template->render($original->variables ?? []);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $operator = AdminUser::find(auth('admin')->id());
        $record = SmsRecord::create([
            'send_no' => $this->nextSendNo(),
            'template_id' => $template->id,
            'template_code' => $template->code,
            'template_name' => $template->name,
            'mobile' => $original->mobile,
            'receiver_name' => $original->receiver_name,
            'content' => $content,
            'variables' => $original->variables,
            'status' => 'pending',
            'created_by' => auth('admin')->id(),
            'creator_name' => $operator?->real_name ?: $operator?->username,
        ]);

        $record->update(['status' => 'success', 'sent_at' => now()]);

        return $this->created($this->recordCard($record->fresh()), '重发成功');
    }

    // ---------- helpers ----------

    private function validateTemplate(Request $request): array
    {
        return $request->validate([
            'code' => 'required|string|max:50|regex:/^[A-Za-z0-9_\-]+$/',
            'name' => 'required|string|max:100',
            'content' => 'required|string|max:500',
            'variables' => 'nullable|array',
            'remark' => 'nullable|string|max:255',
            'status' => 'nullable|integer|in:0,1',
        ]);
    }

    private function templateCard(SmsTemplate $t): array
    {
        return [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->name,
            'content' => $t->content,
            'variables' => $t->variables ?? [],
            'remark' => $t->remark,
            'status' => (int) $t->status,
            'status_label' => SmsTemplate::statusLabel((int) $t->status),
            'status_tag_type' => SmsTemplate::statusTagType((int) $t->status),
            'created_at' => $t->created_at?->toDateTimeString(),
        ];
    }

    private function recordCard(SmsRecord $r): array
    {
        return [
            'id' => $r->id,
            'send_no' => $r->send_no,
            'template_id' => $r->template_id,
            'template_code' => $r->template_code,
            'template_name' => $r->template_name,
            'mobile' => $r->mobile,
            'receiver_name' => $r->receiver_name,
            'content' => $r->content,
            'variables' => $r->variables ?? [],
            'status' => $r->status,
            'status_label' => SmsRecord::statusLabel($r->status),
            'status_tag_type' => SmsRecord::statusTagType($r->status),
            'error_message' => $r->error_message,
            'sent_at' => $r->sent_at?->toDateTimeString(),
            'creator_name' => $r->creator_name,
            'created_at' => $r->created_at?->toDateTimeString(),
        ];
    }

    /** 生成发送编号 SMS+YmdHis+4位 */
    private function nextSendNo(): string
    {
        do {
            $no = 'SMS'.date('YmdHis').str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (SmsRecord::where('send_no', $no)->exists());

        return $no;
    }
}
