<?php

namespace App\Services\System;

use App\Models\System\Attachment;
use App\Services\System\Storage\StorageDriverInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;

class UploadService
{
    protected StorageService $storageService;
    protected ConfigService $configService;

    protected array $allowedImageTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
    protected array $allowedFileTypes = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', '7z', 'txt', 'csv', 'mp4', 'mp3', 'avi', 'mov'];
    protected int $defaultMaxFileSize = 10 * 1024 * 1024; // 10MB
    protected int $chunkMaxFileSize = 2 * 1024 * 1024 * 1024; // 切片上传上限 2GB
    protected int $chunkMaxChunkSize = 10 * 1024 * 1024; // 单个分片上限 10MB
    protected int $chunkExpire = 86400; // 切片缓存有效期 24小时

    public function __construct(StorageService $storageService, ConfigService $configService)
    {
        $this->storageService = $storageService;
        $this->configService = $configService;
    }

    public function getMaxFileSize(): int
    {
        $configMax = $this->configService->getConfigValue('upload_max_size');
        if ($configMax) {
            return (int) $configMax * 1024 * 1024;
        }
        return $this->defaultMaxFileSize;
    }

    public function getAllowedTypes(): array
    {
        $configTypes = $this->configService->getConfigValue('upload_allowed_types');
        if ($configTypes) {
            return array_map('trim', explode(',', $configTypes));
        }
        return array_merge($this->allowedImageTypes, $this->allowedFileTypes);
    }

    /**
     * 上传文件并创建附件记录
     */
    public function upload(UploadedFile $file, string $directory = 'uploads', array $options = []): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        $this->validateFile($file, $extension);

        $driver = $this->resolveDriver();

        $fileName = $this->generateFileName($extension);
        $filePath = $directory . '/' . date('Ymd') . '/' . $fileName;

        // 写入文件
        $driver->put($filePath, file_get_contents($file->getRealPath()));

        // 计算文件哈希
        $hash = md5_file($file->getRealPath());
        $driverName = $driver->getDriverName();

        // 秒传检测
        if (!isset($options['skip_duplicate_check'])) {
            $existing = Attachment::where('hash', $hash)
                ->where('size', $file->getSize())
                ->where('storage_driver', $driverName)
                ->first();

            if ($existing) {
                return $existing->toArray();
            }
        }

        // 获取图片元数据
        $metadata = null;
        $mimeType = $file->getMimeType();
        if (str_starts_with($mimeType, 'image/')) {
            try {
                $size = getimagesize($file->getRealPath());
                if ($size !== false) {
                    $metadata = [
                        'width' => $size[0],
                        'height' => $size[1],
                    ];
                }
            } catch (\Throwable) {
                // 无法读取图片元数据，忽略
            }
        }

        $attachment = Attachment::create([
            'name' => $file->getClientOriginalName(),
            'file_name' => $fileName,
            'path' => $filePath,
            'url' => $driver->url($filePath),
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size' => $file->getSize(),
            'type' => Attachment::detectType($mimeType),
            'storage_driver' => $driver->getDriverName(),
            'hash' => $hash,
            'user_id' => $options['user_id'] ?? null,
            'directory' => $directory,
            'metadata' => $metadata,
            'description' => $options['description'] ?? null,
        ]);

        return $attachment->toArray();
    }

    /**
     * 批量上传
     */
    public function uploadMultiple(array $files, string $directory = 'uploads', array $options = []): array
    {
        $results = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $results[] = $this->upload($file, $directory, $options);
            }
        }
        return $results;
    }

    /**
     * Base64 图片上传
     */
    public function uploadBase64(string $base64, string $directory = 'uploads', ?string $fileName = null, array $options = []): array
    {
        if (!preg_match('/^data:(\w+\/(\w+));base64,/', $base64, $matches)) {
            throw new \Exception('无效的Base64图片数据');
        }

        $mimeType = $matches[1];
        $extension = $matches[2];
        $data = base64_decode(substr($base64, strpos($base64, ',') + 1));

        if (!$data) {
            throw new \Exception('Base64解码失败');
        }

        $driver = $this->resolveDriver();
        $fileName = $fileName ?: $this->generateFileName($extension);
        $filePath = $directory . '/' . date('Ymd') . '/' . $fileName;

        $driver->put($filePath, $data);

        $hash = md5($data);

        $attachment = Attachment::create([
            'name' => $fileName,
            'file_name' => $fileName,
            'path' => $filePath,
            'url' => $driver->url($filePath),
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size' => strlen($data),
            'type' => Attachment::detectType($mimeType),
            'storage_driver' => $driver->getDriverName(),
            'hash' => $hash,
            'user_id' => $options['user_id'] ?? null,
            'directory' => $directory,
        ]);

        return $attachment->toArray();
    }

    /**
     * 初始化切片上传
     */
    public function initChunkUpload(string $fileName, int $fileSize, string $fileHash, int $chunkSize, string $directory = 'uploads', array $options = []): array
    {
        if ($fileSize > $this->chunkMaxFileSize) {
            throw new \Exception('文件大小超过切片上传限制（最大2GB）');
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedTypes = array_merge($this->allowedImageTypes, $this->allowedFileTypes);
        if (!in_array($extension, $allowedTypes)) {
            throw new \Exception('不允许的文件类型：' . $extension);
        }

        $driver = $this->resolveDriver();
        $driverName = $driver->getDriverName();

        // 秒传检测
        if (!isset($options['skip_duplicate_check'])) {
            $existing = Attachment::where('hash', $fileHash)
                ->where('size', $fileSize)
                ->where('storage_driver', $driverName)
                ->first();

            if ($existing) {
                return [
                    'uploaded' => true,
                    'data' => $existing->toArray(),
                ];
            }
        }

        $uploadId = Str::uuid()->toString();

        // S3 分片大小最小 5MB
        if ($driver->supportsMultipart()) {
            $chunkSize = max($chunkSize, 5 * 1024 * 1024); // S3 最小 5MB
        }
        $chunkSize = max($chunkSize, 1024 * 1024); // 最小 1MB
        $totalChunks = (int) ceil($fileSize / $chunkSize);

        // 生成最终文件路径
        $targetFileName = $this->generateFileName($extension);
        $targetPath = $directory . '/' . date('Ymd') . '/' . $targetFileName;

        $meta = [
            'upload_id' => $uploadId,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'file_hash' => $fileHash,
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks,
            'directory' => $directory,
            'extension' => $extension,
            'options' => $options,
            'uploaded_chunks' => [],
            'target_path' => $targetPath,
            'target_file_name' => $targetFileName,
            'multipart_upload_id' => null,
            'parts' => [],
        ];

        // S3 类驱动：在桶内初始化 Multipart Upload
        if ($driver->supportsMultipart()) {
            $multipartId = $driver->initMultipart($targetPath);
            $meta['multipart_upload_id'] = $multipartId;
        }

        Cache::put("chunk_upload:{$uploadId}", $meta, $this->chunkExpire);

        return [
            'uploaded' => false,
            'upload_id' => $uploadId,
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks,
            'uploaded_chunks' => [],
        ];
    }

    /**
     * 上传单个分片
     */
    public function uploadChunk(string $uploadId, int $chunkIndex, UploadedFile $chunkFile): array
    {
        $meta = Cache::get("chunk_upload:{$uploadId}");
        if (!$meta) {
            throw new \Exception('上传任务不存在或已过期，请重新初始化上传');
        }

        if ($chunkIndex < 0 || $chunkIndex >= $meta['total_chunks']) {
            throw new \Exception('分片序号无效');
        }

        $chunkSize = $chunkFile->getSize();
        if ($chunkSize > $this->chunkMaxChunkSize) {
            throw new \Exception('分片大小超过限制（最大' . ($this->chunkMaxChunkSize / 1024 / 1024) . 'MB）');
        }

        // 幂等：跳过已上传分片
        if (in_array($chunkIndex, $meta['uploaded_chunks'])) {
            return [
                'chunk_index' => $chunkIndex,
                'uploaded_chunks' => $meta['uploaded_chunks'],
                'total_chunks' => $meta['total_chunks'],
                'is_complete' => count($meta['uploaded_chunks']) === $meta['total_chunks'],
            ];
        }

        $driver = $this->resolveDriver();

        if ($driver->supportsMultipart() && $meta['multipart_upload_id']) {
            // S3 类驱动：直接上传到桶内（使用流避免内存缓存）
            $stream = fopen($chunkFile->getRealPath(), 'rb');
            $etag = $driver->uploadPart(
                $meta['target_path'],
                $meta['multipart_upload_id'],
                $chunkIndex + 1, // S3 PartNumber 从 1 开始
                $stream,
            );
            if (is_resource($stream)) {
                fclose($stream);
            }

            $meta['parts'][$chunkIndex] = [
                'part_number' => $chunkIndex + 1,
                'etag' => $etag,
            ];
        } else {
            // 本地驱动：存储到临时目录
            $chunkDir = storage_path("app/chunks/{$uploadId}");
            if (!is_dir($chunkDir)) {
                mkdir($chunkDir, 0755, true);
            }
            $chunkFile->move($chunkDir, "chunk_{$chunkIndex}");
        }

        $meta['uploaded_chunks'][] = $chunkIndex;
        Cache::put("chunk_upload:{$uploadId}", $meta, $this->chunkExpire);

        return [
            'chunk_index' => $chunkIndex,
            'uploaded_chunks' => $meta['uploaded_chunks'],
            'total_chunks' => $meta['total_chunks'],
            'is_complete' => count($meta['uploaded_chunks']) === $meta['total_chunks'],
        ];
    }

    /**
     * 合并所有分片
     */
    public function mergeChunks(string $uploadId): array
    {
        $meta = Cache::get("chunk_upload:{$uploadId}");
        if (!$meta) {
            throw new \Exception('上传任务不存在或已过期');
        }

        $totalChunks = $meta['total_chunks'];
        $uploadedChunks = $meta['uploaded_chunks'];

        if (count($uploadedChunks) !== $totalChunks) {
            $missing = array_diff(range(0, $totalChunks - 1), $uploadedChunks);
            throw new \Exception('分片未全部上传完成，缺少分片：' . implode(',', $missing));
        }

        $extension = $meta['extension'];
        $directory = $meta['directory'];
        $options = $meta['options'];
        $filePath = $meta['target_path'];
        $fileName = $meta['target_file_name'];

        $driver = $this->resolveDriver();
        $driverName = $driver->getDriverName();

        if ($driver->supportsMultipart() && $meta['multipart_upload_id']) {
            // S3 类驱动：在桶内完成合并
            $parts = [];
            for ($i = 0; $i < $totalChunks; $i++) {
                if (!isset($meta['parts'][$i])) {
                    throw new \Exception("分片 {$i} 的上传信息缺失");
                }
                $parts[] = $meta['parts'][$i];
            }

            $driver->completeMultipart($filePath, $meta['multipart_upload_id'], $parts);

            $fileSize = $meta['file_size'];
            $hash = $meta['file_hash'];
            $mimeType = $this->guessMimeTypeByExtension($extension);
            $metadata = null;
        } else {
            // 本地驱动：从临时目录合并
            [$hash, $fileSize, $mimeType, $metadata] = $this->mergeLocalChunks(
                $driver, $uploadId, $filePath, $totalChunks, $extension, $options,
            );
        }

        // 秒传检测
        if (!isset($options['skip_duplicate_check'])) {
            $existing = Attachment::where('hash', $hash)
                ->where('size', $fileSize)
                ->where('storage_driver', $driverName)
                ->first();

            if ($existing) {
                $this->cleanupChunks($uploadId);
                return $existing->toArray();
            }
        }

        $attachment = Attachment::create([
            'name' => $meta['file_name'],
            'file_name' => $fileName,
            'path' => $filePath,
            'url' => $driver->url($filePath),
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size' => $fileSize,
            'type' => Attachment::detectType($mimeType),
            'storage_driver' => $driver->getDriverName(),
            'hash' => $hash,
            'user_id' => $options['user_id'] ?? null,
            'directory' => $directory,
            'metadata' => $metadata,
            'description' => $options['description'] ?? null,
        ]);

        $this->cleanupChunks($uploadId);

        return $attachment->toArray();
    }

    /**
     * 本地分片合并（将临时分片文件合并后上传到存储驱动）
     */
    protected function mergeLocalChunks(
        StorageDriverInterface $driver,
        string $uploadId,
        string $filePath,
        int $totalChunks,
        string $extension,
        array $options,
    ): array {
        $chunkDir = storage_path("app/chunks/{$uploadId}");
        $tmpPath = storage_path("app/chunks/{$uploadId}/merged_tmp");

        $outStream = fopen($tmpPath, 'wb');
        if (!$outStream) {
            throw new \Exception('创建临时文件失败');
        }

        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = "{$chunkDir}/chunk_{$i}";
                if (!file_exists($chunkPath)) {
                    throw new \Exception("分片 {$i} 不存在");
                }
                $inStream = fopen($chunkPath, 'rb');
                if (!$inStream) {
                    throw new \Exception("读取分片 {$i} 失败");
                }
                stream_copy_to_stream($inStream, $outStream);
                fclose($inStream);
            }
            fclose($outStream);
        } catch (\Throwable $e) {
            if (isset($outStream) && is_resource($outStream)) {
                fclose($outStream);
            }
            throw $e;
        }

        $hash = md5_file($tmpPath);
        $fileSize = filesize($tmpPath);
        $driverName = $driver->getDriverName();

        // 秒传检测
        if (!isset($options['skip_duplicate_check'])) {
            $existing = Attachment::where('hash', $hash)
                ->where('size', $fileSize)
                ->where('storage_driver', $driverName)
                ->first();

            if ($existing) {
                @unlink($tmpPath);
                throw new \Exception('__DUPLICATE__' . json_encode($existing->toArray()));
            }
        }

        // 写入存储驱动（直接流式传输，避免大文件内存溢出）
        $stream = fopen($tmpPath, 'rb');
        if (!$stream) {
            throw new \Exception('读取合并文件失败');
        }
        $driver->put($filePath, $stream);
        fclose($stream);

        $mimeType = $this->guessMimeType($tmpPath, $extension);

        // 图片元数据
        $metadata = null;
        if (str_starts_with($mimeType, 'image/')) {
            try {
                $size = getimagesize($tmpPath);
                if ($size !== false) {
                    $metadata = [
                        'width' => $size[0],
                        'height' => $size[1],
                    ];
                }
            } catch (\Throwable) {
                // 忽略
            }
        }

        @unlink($tmpPath);

        return [$hash, $fileSize, $mimeType, $metadata];
    }

    /**
     * 获取已上传的分片列表（断点续传）
     */
    public function getUploadedChunks(string $uploadId): array
    {
        $meta = Cache::get("chunk_upload:{$uploadId}");
        if (!$meta) {
            throw new \Exception('上传任务不存在或已过期');
        }

        // 本地模式：校验临时文件
        if (!$meta['multipart_upload_id']) {
            $chunkDir = storage_path("app/chunks/{$uploadId}");
            $actualChunks = [];
            foreach ($meta['uploaded_chunks'] as $index) {
                if (file_exists("{$chunkDir}/chunk_{$index}")) {
                    $actualChunks[] = $index;
                }
            }
            if (count($actualChunks) !== count($meta['uploaded_chunks'])) {
                $meta['uploaded_chunks'] = $actualChunks;
                Cache::put("chunk_upload:{$uploadId}", $meta, $this->chunkExpire);
            }
        }

        return [
            'upload_id' => $uploadId,
            'uploaded_chunks' => $meta['uploaded_chunks'],
            'total_chunks' => $meta['total_chunks'],
        ];
    }

    /**
     * 取消切片上传
     */
    public function cancelChunkUpload(string $uploadId): bool
    {
        $meta = Cache::get("chunk_upload:{$uploadId}");

        // S3 类驱动：中止桶内的 Multipart Upload
        if ($meta && $meta['multipart_upload_id']) {
            $driver = $this->resolveDriver();
            $driver->abortMultipart($meta['target_path'], $meta['multipart_upload_id']);
        }

        $this->cleanupChunks($uploadId);
        return true;
    }

    /**
     * 清理切片临时文件和缓存
     */
    protected function cleanupChunks(string $uploadId): void
    {
        $chunkDir = storage_path("app/chunks/{$uploadId}");
        if (is_dir($chunkDir)) {
            $files = glob("{$chunkDir}/*");
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($chunkDir);
        }

        Cache::forget("chunk_upload:{$uploadId}");
    }

    /**
     * 删除附件（同时删除物理文件）
     */
    public function deleteAttachment(int $attachmentId): bool
    {
        $attachment = Attachment::findOrFail($attachmentId);
        return $this->deleteAttachmentEntity($attachment);
    }

    /**
     * 批量删除附件
     */
    public function deleteAttachments(array $ids): bool
    {
        $attachments = Attachment::whereIn('id', $ids)->get();
        foreach ($attachments as $attachment) {
            $this->deleteAttachmentEntity($attachment);
        }
        return true;
    }

    protected function deleteAttachmentEntity(Attachment $attachment): bool
    {
        try {
            $driver = $this->storageService->getDriverByName($attachment->storage_driver);
            $driver->delete($attachment->path);
        } catch (\Throwable) {
            // 物理文件删除失败不影响记录删除
        }

        $attachment->delete();
        return true;
    }

    /**
     * 仅删除物理文件（不删记录）
     */
    public function deleteFile(string $path, ?string $driver = null): bool
    {
        $storageDriver = $driver
            ? $this->storageService->getDriverByName($driver)
            : $this->storageService->getDefaultDriver();

        return $storageDriver->delete($path);
    }

    public function getAttachmentUrl(int $attachmentId): ?string
    {
        $attachment = Attachment::find($attachmentId);
        return $attachment?->url;
    }

    public function getAttachmentById(int $attachmentId): ?Attachment
    {
        return Attachment::find($attachmentId);
    }

    public function getAttachmentsByIds(array $ids): array
    {
        return Attachment::whereIn('id', $ids)->get()->toArray();
    }

    /**
     * 解析存储驱动（始终使用默认驱动）
     */
    protected function resolveDriver(): StorageDriverInterface
    {
        return $this->storageService->getDefaultDriver();
    }

    protected function validateFile(UploadedFile $file, string $extension): bool
    {
        $maxSize = $this->getMaxFileSize();
        if ($file->getSize() > $maxSize) {
            throw new \Exception('文件大小超过限制（最大' . round($maxSize / 1024 / 1024) . 'MB）');
        }

        $allowedTypes = $this->getAllowedTypes();
        if (!in_array($extension, $allowedTypes)) {
            throw new \Exception('不允许的文件类型：' . $extension);
        }

        return true;
    }

    protected function generateFileName(string $extension): string
    {
        return uniqid() . '_' . Str::random(6) . '.' . $extension;
    }

    protected function guessMimeType(string $filePath, string $extension): string
    {
        $mimeTypes = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
            'txt' => 'text/plain', 'csv' => 'text/csv',
            'mp4' => 'video/mp4', 'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime', 'mp3' => 'audio/mpeg',
        ];

        return $mimeTypes[$extension] ?? mime_content_type($filePath) ?: 'application/octet-stream';
    }

    /**
     * 根据扩展名猜测 MIME 类型（不依赖本地文件）
     */
    protected function guessMimeTypeByExtension(string $extension): string
    {
        $mimeTypes = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
            'txt' => 'text/plain', 'csv' => 'text/csv',
            'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}
