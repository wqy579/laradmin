<?php

namespace Modules\System\Services\Storage;

use Illuminate\Support\Facades\Storage;

/**
 * RustFS 存储驱动 - 基于 S3 兼容协议
 *
 * RustFS 是兼容 S3 接口的对象存储系统，实现方式与 MinIO 一致：
 * 使用 path-style URL，固定 use_path_style_endpoint=true。
 */
class RustfsStorageDriver extends S3StorageDriver
{
    public function __construct(array $config = [])
    {
        $this->diskName = 'rustfs';
        $this->config = $config;
        $this->registerDisk($config);
    }

    protected function registerDisk(array $config): void
    {
        $endpoint = $config['endpoint'] ?? env('RUSTFS_ENDPOINT', '');
        $bucket = $config['bucket'] ?? env('RUSTFS_BUCKET', '');

        app('config')->set("filesystems.disks.{$this->diskName}", [
            'driver' => 's3',
            'key' => $config['access_key'] ?? $config['key'] ?? env('RUSTFS_ACCESS_KEY', ''),
            'secret' => $config['secret_key'] ?? $config['secret'] ?? env('RUSTFS_SECRET_KEY', ''),
            'region' => $config['region'] ?? env('RUSTFS_REGION', 'us-east-1'),
            'bucket' => $bucket,
            'url' => $config['url'] ?? env('RUSTFS_URL', ''),
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'throw' => true,
        ]);

        Storage::forgetDisk($this->diskName);
    }

    /**
     * RustFS URL：path-style 格式 {endpoint}/{bucket}/{path}
     *
     * 当 customUrl 已包含 bucket 路径段时直接使用，否则自动补 bucket，
     * 避免用户把 endpoint 当作 url 填入时丢掉 bucket。
     */
    public function url(string $path): string
    {
        $customUrl = rtrim($this->config['url'] ?? '', '/');
        $endpoint = rtrim($this->config['endpoint'] ?? env('RUSTFS_ENDPOINT', ''), '/');
        $bucket = $this->config['bucket'] ?? env('RUSTFS_BUCKET', '');

        if ($customUrl) {
            // customUrl 末尾已是 /{bucket} 视为已包含 bucket，直接拼接
            $bucketSuffix = $bucket ? '/'.trim($bucket, '/') : '';
            $endsWithBucket = $bucketSuffix !== '' && str_ends_with($customUrl, $bucketSuffix);

            $prefix = $endsWithBucket ? $customUrl : ($bucket ? $customUrl.$bucketSuffix : $customUrl);

            return $prefix.'/'.$path;
        }

        if ($endpoint && $bucket) {
            return $endpoint.'/'.trim($bucket, '/').'/'.$path;
        }

        return Storage::disk($this->diskName)->url($path);
    }

    public function getDriverName(): string
    {
        return 'rustfs';
    }

    public function testConnection(): bool
    {
        try {
            Storage::disk($this->diskName)->files();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
