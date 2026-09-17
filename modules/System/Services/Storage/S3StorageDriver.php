<?php

namespace Modules\System\Services\Storage;

use Illuminate\Support\Facades\Storage;

class S3StorageDriver implements StorageDriverInterface
{
    protected string $diskName;

    protected array $config;

    public function __construct(array $config = [])
    {
        $this->diskName = $config['disk_name'] ?? 's3';
        $this->config = $config;
        $this->registerDisk($config);
    }

    /**
     * 动态注册 S3 磁盘配置
     */
    protected function registerDisk(array $config): void
    {
        app('config')->set("filesystems.disks.{$this->diskName}", [
            'driver' => 's3',
            'key' => $config['key'] ?? env('AWS_ACCESS_KEY_ID', ''),
            'secret' => $config['secret'] ?? env('AWS_SECRET_ACCESS_KEY', ''),
            'region' => $config['region'] ?? env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => $config['bucket'] ?? env('AWS_BUCKET', ''),
            'url' => $config['url'] ?? env('AWS_URL', ''),
            'endpoint' => $config['endpoint'] ?? env('AWS_ENDPOINT', ''),
            'use_path_style_endpoint' => $config['use_path_style_endpoint'] ?? env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => true,
        ]);

        // 清除已缓存的磁盘实例，确保使用新配置
        Storage::forgetDisk($this->diskName);
    }

    /**
     * 获取 S3 客户端
     */
    protected function getS3Client()
    {
        return Storage::disk($this->diskName)->getClient();
    }

    /**
     * 获取桶名
     */
    protected function getBucket(): string
    {
        return config("filesystems.disks.{$this->diskName}.bucket", '');
    }

    public function put(string $path, mixed $content): bool
    {
        try {
            return Storage::disk($this->diskName)->put($path, $content);
        } catch (\Throwable $e) {
            throw new \Exception('文件上传到云存储失败：'.$e->getMessage(), 0, $e);
        }
    }

    public function get(string $path): ?string
    {
        $disk = Storage::disk($this->diskName);
        if ($disk->exists($path)) {
            return $disk->get($path);
        }

        return null;
    }

    public function delete(string $path): bool
    {
        $disk = Storage::disk($this->diskName);
        if ($disk->exists($path)) {
            return $disk->delete($path);
        }

        return true;
    }

    public function deleteMultiple(array $paths): bool
    {
        $disk = Storage::disk($this->diskName);

        return $disk->delete($paths);
    }

    public function exists(string $path): bool
    {
        return Storage::disk($this->diskName)->exists($path);
    }

    public function url(string $path): string
    {
        // 优先使用自定义 URL（如 CDN 域名）
        $customUrl = $this->config['url'] ?? '';
        if ($customUrl) {
            return rtrim($customUrl, '/').'/'.$path;
        }

        // 从 endpoint + bucket 构建
        $endpoint = $this->config['endpoint'] ?? '';
        $bucket = $this->config['bucket'] ?? '';
        if ($endpoint && $bucket) {
            return rtrim($endpoint, '/').'/'.$bucket.'/'.$path;
        }

        return Storage::disk($this->diskName)->url($path);
    }

    public function path(string $path): string
    {
        return $this->url($path);
    }

    public function size(string $path): int
    {
        return Storage::disk($this->diskName)->size($path);
    }

    public function getDriverName(): string
    {
        return 's3';
    }

    public function testConnection(): bool
    {
        try {
            Storage::disk($this->diskName)->exists('/');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function supportsMultipart(): bool
    {
        return true;
    }

    public function initMultipart(string $path): ?string
    {
        $result = $this->getS3Client()->createMultipartUpload([
            'Bucket' => $this->getBucket(),
            'Key' => $path,
        ]);

        return $result->get('UploadId');
    }

    public function uploadPart(string $path, string $uploadId, int $partNumber, mixed $content): string
    {
        $result = $this->getS3Client()->uploadPart([
            'Bucket' => $this->getBucket(),
            'Key' => $path,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
            'Body' => $content,
        ]);

        return $result->get('ETag');
    }

    public function completeMultipart(string $path, string $uploadId, array $parts): bool
    {
        $multipartParts = [];
        foreach ($parts as $part) {
            $multipartParts[] = [
                'PartNumber' => $part['part_number'],
                'ETag' => $part['etag'],
            ];
        }

        $this->getS3Client()->completeMultipartUpload([
            'Bucket' => $this->getBucket(),
            'Key' => $path,
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => $multipartParts,
            ],
        ]);

        return true;
    }

    public function abortMultipart(string $path, string $uploadId): bool
    {
        try {
            $this->getS3Client()->abortMultipartUpload([
                'Bucket' => $this->getBucket(),
                'Key' => $path,
                'UploadId' => $uploadId,
            ]);
        } catch (\Throwable) {
            // 忽略中止失败
        }

        return true;
    }
}
