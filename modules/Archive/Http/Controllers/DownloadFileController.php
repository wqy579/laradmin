<?php

namespace Modules\Archive\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Archive\Models\DownloadFile;
use Modules\Archive\Services\DownloadFileService;

/**
 * 下载中心（F6 块3）。
 *
 * 上传的过滤规则全在 DownloadFileService（扩展名白名单 + 真实 MIME 自洽 + 随机磁盘名），
 * 控制器这一层只做字段格式校验与存在性判断：
 * 安全相关的拒绝要带上原因（"探测到 text/html"），不该被通用验证器吞成"格式错误"。
 */
class DownloadFileController extends Controller
{
    use ResponseTrait;

    public function __construct(private readonly DownloadFileService $service) {}

    public function index(Request $request)
    {
        return $this->paginated($this->service->search([
            'keyword' => $request->input('keyword'),
            'category' => $request->input('category'),
            'status' => $request->input('status'),
            'page_size' => $request->integer('page_size', 30),
        ]));
    }

    public function categories()
    {
        return $this->success($this->service->categories());
    }

    public function show($id)
    {
        $file = DownloadFile::find($id);
        if (! $file) {
            return $this->notFound('文件不存在');
        }
        // 记录在、磁盘没了：列表里能看到但下不了，比点下去 500 好解释
        $file['missing'] = ! $this->service->exists($file);

        return $this->success($file);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'file' => 'required|file',
            'name' => 'nullable|string|max:150',
            'category' => 'required|string|max:50',
            'version' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:255',
            'sort' => 'nullable|integer|min:0',
            'status' => ['nullable', Rule::in([DownloadFile::STATUS_PUBLISHED, DownloadFile::STATUS_DISABLED])],
        ], [], [
            'file' => '文件',
            'category' => '分组',
        ]);

        $file = $this->service->store($request->file('file'), $data, $this->uid(), $this->uname());

        return $this->created($file, '已上传');
    }

    public function update(Request $request, $id)
    {
        $file = DownloadFile::find($id);
        if (! $file) {
            return $this->notFound('文件不存在');
        }
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'category' => 'required|string|max:50',
            'version' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:255',
            'sort' => 'nullable|integer|min:0',
            'status' => ['nullable', Rule::in([DownloadFile::STATUS_PUBLISHED, DownloadFile::STATUS_DISABLED])],
        ], [], [
            'name' => '名称',
            'category' => '分组',
        ]);

        return $this->success($this->service->update($file, $data, $this->uid(), $this->uname()), '已更新');
    }

    /**
     * 下载。
     *
     * 下架的文件不放行：下架的语义就是"别再发出去了"，
     * 只在前端隐藏按钮是不够的，接口得自己守住。
     */
    public function download($id)
    {
        $file = DownloadFile::find($id);
        if (! $file) {
            return $this->notFound('文件不存在');
        }
        if ($file->status !== DownloadFile::STATUS_PUBLISHED) {
            return $this->error('该文件已下架', 422);
        }
        if (! $this->service->exists($file)) {
            return $this->error('文件已丢失，请联系管理员重新上传', 404);
        }

        // 计数在返回响应之前：这里跑在 Swoole 常驻进程里，
        // 用 send() + exit 的旧写法会直接把 worker 打掉
        DownloadFile::where('id', $file->id)->increment('download_count');

        return $this->service->download($file);
    }

    public function destroy($id)
    {
        $file = DownloadFile::find($id);
        if (! $file) {
            return $this->notFound('文件不存在');
        }
        $this->service->destroy($file);

        return $this->success(null, '已删除');
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
