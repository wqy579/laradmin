<?php

namespace Modules\System\Services\Storage;

interface StorageDriverInterface
{
    /**
     * 写入文件
     */
    public function put(string $path, mixed $content): bool;

    /**
     * 获取文件内容
     */
    public function get(string $path): ?string;

    /**
     * 删除文件
     */
    public function delete(string $path): bool;

    /**
     * 批量删除文件
     */
    public function deleteMultiple(array $paths): bool;

    /**
     * 判断文件是否存在
     */
    public function exists(string $path): bool;

    /**
     * 获取文件访问URL
     */
    public function url(string $path): string;

    /**
     * 获取文件的完整路径（本地存储适用）
     */
    public function path(string $path): string;

    /**
     * 获取文件大小
     */
    public function size(string $path): int;

    /**
     * 获取驱动名称
     */
    public function getDriverName(): string;

    /**
     * 测试连接是否正常
     */
    public function testConnection(): bool;

    /**
     * 是否支持服务端分片合并（S3 Multipart Upload 等）
     * 返回 false 表示使用本地合并模式
     */
    public function supportsMultipart(): bool;

    /**
     * 初始化服务端分片上传，返回 multipart upload id
     * 返回 null 表示不支持，将使用本地合并
     */
    public function initMultipart(string $path): ?string;

    /**
     * 上传单个分片到服务端
     * @return string 返回分片的 ETag
     */
    public function uploadPart(string $path, string $uploadId, int $partNumber, mixed $content): string;

    /**
     * 完成分片上传，在服务端合并所有分片
     * @param array $parts [['part_number' => int, 'etag' => string], ...]
     */
    public function completeMultipart(string $path, string $uploadId, array $parts): bool;

    /**
     * 取消分片上传，清理服务端资源
     */
    public function abortMultipart(string $path, string $uploadId): bool;
}
