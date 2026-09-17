<?php

namespace Modules\System\Services\Storage;

use Illuminate\Support\Facades\Storage;

/**
 * 阿里云 OSS 存储驱动 - 基于 S3 兼容协议
 */
class OssStorageDriver extends S3StorageDriver
{
    public function __construct(array $config = [])
    {
        $this->diskName = 'oss';
        $this->config = $config;
        $this->registerDisk($config);
    }

    protected function registerDisk(array $config): void
    {
        $bucket = $config['bucket'] ?? env('OSS_BUCKET', '');
        $endpoint = $config['endpoint'] ?? env('OSS_ENDPOINT', '');
        $region = $config['region'] ?? env('OSS_REGION', 'cn-hangzhou');

        app('config')->set("filesystems.disks.{$this->diskName}", [
            'driver' => 's3',
            'key' => $config['key'] ?? env('OSS_ACCESS_KEY_ID', ''),
            'secret' => $config['secret'] ?? env('OSS_ACCESS_KEY_SECRET', ''),
            'region' => $region,
            'bucket' => $bucket,
            'url' => $config['url'] ?? env('OSS_URL', ''),
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => false,
            'throw' => true,
        ]);

        Storage::forgetDisk($this->diskName);
    }

    /**
     * OSS URL：虚拟托管格式 https://{bucket}.{endpoint}/{path}
     */
    public function url(string $path): string
    {
        $customUrl = $this->config['url'] ?? '';
        if ($customUrl) {
            return rtrim($customUrl, '/').'/'.$path;
        }

        $endpoint = $this->config['endpoint'] ?? env('OSS_ENDPOINT', '');
        $bucket = $this->config['bucket'] ?? env('OSS_BUCKET', '');

        if ($endpoint && $bucket) {
            // 去掉协议前缀，避免 https://bucket.https://endpoint 的问题
            $host = preg_replace('#^https?://#', '', $endpoint);

            return 'https://'.$bucket.'.'.$host.'/'.$path;
        }

        return Storage::disk($this->diskName)->url($path);
    }

    public function getDriverName(): string
    {
        return 'oss';
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
