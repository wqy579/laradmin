<?php

namespace Modules\Archive\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Archive\Models\DownloadFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 下载中心业务层（F6 块3）。
 *
 * 上传是这个功能唯一的攻击面，所以这里的取舍都偏保守：
 *
 *  1. 磁盘文件名 = Str::random(40) . '.' + 我们自己拼的扩展名，
 *     用户给的 originalName 一个字符都不参与路径构造 —— 路径穿越（../../）、
 *     空字节截断（x.php%00.png）、双扩展名（evil.php.jpg）三条经典攻击都无路径可走。
 *  2. 扩展名白名单**只 narrow 不 widen** System 模块 UploadService 的 allowedFileTypes，
 *     并且刻意排除 svg：svg 是 XML，能内嵌 <script>，落到 public 盘就是存储型 XSS
 *     （UploadService 的图片白名单里有 svg，那是头像场景，我们不需要）。
 *  3. 扩展名与 finfo 探测到的真实 MIME 必须互相自洽（见 MIME_MAP）：
 *     把 .html 改名成 .xlsx 想骗过检查是无效的，真 MIME 那一关过不去。
 *  4. 落盘走 local 盘（root = storage/app/private，在 document root 之外），
 *     只有本模块的 download 接口能读，URL 里不存在直链。
 *     因此上传内容即使本身是 HTML，浏览器也拿不到它的 URL，执行不了。
 *  5. Content-Type 由 StreamedResponse 从磁盘真实内容推断（Filesystem::mimeType），
 *     不接受前端传的任何类型头。
 *
 * 大小上限取 20MB：既大于 UploadService 的 10MB 默认单文件上限（模板类文件合理），
 * 也小于 php.ini 常见的 50MB 上传限制，不会到了这里才被 PHP 截断成半个文件。
 */
class DownloadFileService
{
    public const CATEGORIES = ['模板', '手册', '其它'];

    /** 单个文件大小上限 20MB */
    public const MAX_SIZE = 20 * 1024 * 1024;

    /** 扩展名白名单（下载中心语义：办公文档 + 压缩包 + 常见图片，无 svg/html/可执行） */
    public const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'txt', 'csv', 'png', 'jpg', 'jpeg'];

    /**
     * 扩展名 -> 可接受的真实 MIME 集合。
     *
     * 一个扩展名对应多个 MIME 是现实存在的（.doc 其实是 OLE 复合文档，
     * .zip 与 .docx/.xlsx 在 finfo 眼里是同一种 container），所以这里是"可接受集合"
     * 而不是精确匹配；但集合之外的组合一律拒绝。
     */
    private const MIME_MAP = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/vnd.oasis.opendocument.text', 'application/octet-stream', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/octet-stream', 'application/x-ole-storage'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/octet-stream', 'application/x-ole-storage'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'txt' => ['text/plain', 'application/octet-stream', 'text/csv', 'inode/x-empty'],
        'csv' => ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/octet-stream', 'inode/x-empty'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
    ];

    /** 存储目录（相对 local 盘根，即 storage/app/private/downloads） */
    private const DIR = 'downloads';

    public function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    public function search(array $filters): LengthAwarePaginator
    {
        $query = DownloadFile::query();

        if (! empty($filters['keyword'])) {
            $like = '%'.str_replace('%', '\%', $filters['keyword']).'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('file_name', 'like', $like)
                    ->orWhere('description', 'like', $like);
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
            ->orderBy('sort')
            ->orderByDesc('id')
            ->paginate($pageSize);
    }

    /** 分组及各自文件数（前端左侧分组导航） */
    public function categories(): array
    {
        $counts = DownloadFile::query()
            ->groupBy('category')
            ->selectRaw('category, count(*) as cnt')
            ->pluck('cnt', 'category');

        $list = [];
        foreach (self::CATEGORIES as $name) {
            $list[] = ['name' => $name, 'count' => (int) ($counts[$name] ?? 0)];
        }
        foreach ($counts as $name => $cnt) {
            if (! in_array($name, self::CATEGORIES, true)) {
                $list[] = ['name' => $name, 'count' => (int) $cnt];
            }
        }

        return ['list' => $list, 'total' => (int) $counts->sum()];
    }

    /**
     * 上传并登记。
     *
     * @throws ValidationException 类型/大小/MIME 不符
     */
    public function store(UploadedFile $file, array $data, ?int $uid = null, ?string $uname = null): DownloadFile
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $original = $this->displayFileName($file->getClientOriginalName(), $extension);

        $this->assertExtension($extension);
        $this->assertSize($file);
        $this->assertMime($file, $extension);

        $storedName = Str::random(40).'.'.$extension;
        // putFileAs 只按我们的 $storedName 命名；$file->getPathname() 是 PHP 的临时文件，
        // 全程不接触用户提供的文件名
        $this->disk()->putFileAs(self::DIR, $file, $storedName);

        return DownloadFile::create([
            'name' => $data['name'] ?? $original,
            'category' => $data['category'] ?? '模板',
            'file_name' => $original,
            'stored_name' => $storedName,
            'extension' => $extension,
            'mime_type' => (string) $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
            'version' => $data['version'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => ($data['status'] ?? DownloadFile::STATUS_PUBLISHED) === DownloadFile::STATUS_DISABLED
                ? DownloadFile::STATUS_DISABLED
                : DownloadFile::STATUS_PUBLISHED,
            'sort' => (int) ($data['sort'] ?? 0),
            'created_by' => $uid,
            'created_by_name' => $uname,
            'updated_by' => $uid,
            'updated_by_name' => $uname,
        ]);
    }

    /** 只改元信息，不换文件 */
    public function update(DownloadFile $file, array $data, ?int $uid = null, ?string $uname = null): DownloadFile
    {
        $payload = [
            'name' => $data['name'] ?? $file->name,
            'category' => $data['category'] ?? $file->category,
            'version' => $data['version'] ?? null,
            'description' => $data['description'] ?? null,
            'sort' => (int) ($data['sort'] ?? 0),
            'status' => ($data['status'] ?? $file->status) === DownloadFile::STATUS_DISABLED
                ? DownloadFile::STATUS_DISABLED
                : DownloadFile::STATUS_PUBLISHED,
            'updated_by' => $uid,
            'updated_by_name' => $uname,
        ];
        $file->update($payload);

        return $file->fresh();
    }

    /**
     * 下载响应。存在性由控制器先判（好返回统一的 404 JSON）。
     *
     * 文件名走 Content-Disposition，不用来做路径 —— 磁盘上是 stored_name，
     * 与展示名彻底解耦，所以展示名里有什么字符都影响不到读哪个文件。
     */
    public function download(DownloadFile $file): StreamedResponse
    {
        return $this->disk()->download($this->path($file), $file->file_name);
    }

    public function exists(DownloadFile $file): bool
    {
        return $file->stored_name ? $this->disk()->exists($this->path($file)) : false;
    }

    public function path(DownloadFile $file): string
    {
        return self::DIR.'/'.$file->stored_name;
    }

    /**
     * 软删只删记录，磁盘文件留着。
     *
     * 理由：模板类文件常被历史单据/文档引用，误删后 restore 应能立刻恢复下载；
     * 真要清磁盘应该是单独的离线清理任务，而不是跟着一次点击走。
     */
    public function destroy(DownloadFile $file): void
    {
        $file->delete();
    }

    /** 白名单里就放行，否则给出可执行的提示（列出允许的类型） */
    private function assertExtension(string $extension): void
    {
        if ($extension === '' || ! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => '不支持的文件类型'.($extension ? '「.'.$extension.'」' : '').'，允许：'
                    .implode('、', self::ALLOWED_EXTENSIONS).'（不支持 svg/html 等可在浏览器内执行的类型）',
            ]);
        }
    }

    private function assertSize(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_SIZE) {
            throw ValidationException::withMessages([
                'file' => '文件不能超过 '.round(self::MAX_SIZE / 1024 / 1024).'MB',
            ]);
        }
    }

    /**
     * 扩展名与真实内容必须自洽。
     *
     * 两处探测相互印证：UploadedFile::getMimeType()（finfo 读内容）与
     * 服务端 mime_content_type()。前者在部分环境会退化，所以再取一次磁盘探测兜底 ——
     * 只要有一个探出白名单外的类型就拒，宁可误拒不放过。
     */
    private function assertMime(UploadedFile $file, string $extension): void
    {
        $accepted = self::MIME_MAP[$extension] ?? [];
        $detected = array_filter(array_map(
            fn (?string $m) => $m ? strtolower($m) : null,
            [$file->getMimeType(), $this->probe($file)]
        ));

        if ($detected === []) {
            throw ValidationException::withMessages(['file' => '无法识别文件内容类型，请重新上传']);
        }
        foreach ($detected as $mime) {
            if (! in_array($mime, $accepted, true)) {
                throw ValidationException::withMessages([
                    'file' => '文件内容与扩展名不符（探测到 '.$mime.'），拒绝上传',
                ]);
            }
        }
    }

    private function probe(UploadedFile $file): ?string
    {
        $real = @mime_content_type($file->getRealPath());

        return is_string($real) ? $real : null;
    }

    /**
     * 展示名清洗：只留 basename、掐控制字符、限长。
     *
     * 这个字段会进 Content-Disposition 和前端列表，浏览器/前端对它没有任何二次编码，
     * 所以在这里一次性把它变成"看起来像文件名"的东西；
     * 它同时是响应头的一部分，换行符必须去掉（响应头注入）。
     */
    private function displayFileName(string $original, string $extension): string
    {
        $name = Str::beforeLast($original, '.', '.') === $original
            ? $original
            : Str::beforeLast($original, '.');

        $name = str_replace(['/', '\\', "\0"], '', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '') {
            $name = 'file';
        }

        // 与列宽 190 对齐，留出扩展名的位置
        $name = Str::limit($name, 160, '');
        $suffix = $extension ? '.'.$extension : '';

        return $name.$suffix;
    }
}
