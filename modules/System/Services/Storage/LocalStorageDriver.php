<?php

namespace Modules\System\Services\Storage;

use Illuminate\Support\Facades\Storage;

class LocalStorageDriver implements StorageDriverInterface
{
    protected string $diskName = 'public';

    public function __construct(array $config = [])
    {
        // 本地存储使用 Laravel 的 public disk
    }

    public function put(string $path, mixed $content): bool
    {
        $result = Storage::disk($this->diskName)->put($path, $content);
        if ($result !== false) {
            $fullPath = Storage::disk($this->diskName)->path($path);
            @chmod($fullPath, 0644);
            @chmod(dirname($fullPath), 0755);
        }

        return $result !== false;
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
        $existingPaths = array_filter($paths, fn ($path) => $disk->exists($path));
        if (! empty($existingPaths)) {
            return $disk->delete($existingPaths);
        }

        return true;
    }

    public function exists(string $path): bool
    {
        return Storage::disk($this->diskName)->exists($path);
    }

    public function url(string $path): string
    {
        return Storage::disk($this->diskName)->url($path);
    }

    public function path(string $path): string
    {
        return Storage::disk($this->diskName)->path($path);
    }

    public function size(string $path): int
    {
        return Storage::disk($this->diskName)->size($path);
    }

    public function getDriverName(): string
    {
        return 'local';
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
        return false;
    }

    public function initMultipart(string $path): ?string
    {
        return null;
    }

    public function uploadPart(string $path, string $uploadId, int $partNumber, mixed $content): string
    {
        return '';
    }

    public function completeMultipart(string $path, string $uploadId, array $parts): bool
    {
        return false;
    }

    public function abortMultipart(string $path, string $uploadId): bool
    {
        return true;
    }
}
