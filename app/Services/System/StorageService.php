<?php

namespace App\Services\System;

use App\Services\System\Storage\LocalStorageDriver;
use App\Services\System\Storage\MinIOStorageDriver;
use App\Services\System\Storage\OssStorageDriver;
use App\Services\System\Storage\RustfsStorageDriver;
use App\Services\System\Storage\S3StorageDriver;
use App\Services\System\Storage\StorageDriverInterface;

class StorageService
{
    /**
     * 驱动类映射
     */
    protected array $driverMap = [
        'local' => LocalStorageDriver::class,
        's3' => S3StorageDriver::class,
        'minio' => MinIOStorageDriver::class,
        'oss' => OssStorageDriver::class,
        'rustfs' => RustfsStorageDriver::class,
    ];

    /**
     * 获取默认存储驱动
     * 从 system_setting 读取 storage_driver 值，驱动参数走 config/.env
     */
    public function getDefaultDriver(): StorageDriverInterface
    {
        $driverName = $this->getActiveDriverName();

        return $this->createDriverFromConfig($driverName);
    }

    /**
     * 获取当前激活的驱动名称
     */
    public function getActiveDriverName(): string
    {
        $configService = app(ConfigService::class);
        $driver = $configService->getConfigValue('storage_driver', 'local');

        $supported = array_keys($this->driverMap);
        if (!in_array($driver, $supported)) {
            $driver = 'local';
        }

        return $driver;
    }

    /**
     * 根据驱动名称获取驱动实例
     */
    public function getDriverByName(string $driverName): StorageDriverInterface
    {
        return $this->createDriverFromConfig($driverName);
    }

    /**
     * 从数据库配置创建驱动实例
     */
    protected function createDriverFromConfig(string $driver): StorageDriverInterface
    {
        $driverClass = $this->driverMap[$driver] ?? null;

        if (!$driverClass || !class_exists($driverClass)) {
            throw new \Exception("不支持的存储驱动：{$driver}");
        }

        $configService = app(ConfigService::class);
        $config = $this->resolveDriverParams($driver, $configService);

        return new $driverClass($config);
    }

    /**
     * 从数据库读取驱动参数并映射到驱动构造函数所需的格式
     */
    protected function resolveDriverParams(string $driver, ConfigService $configService): array
    {
        $keyMap = match ($driver) {
            's3' => [
                'key' => 's3_access_key',
                'secret' => 's3_secret_key',
                'region' => 's3_region',
                'bucket' => 's3_bucket',
                'url' => 's3_url',
                'endpoint' => 's3_endpoint',
                'use_path_style_endpoint' => 's3_use_path_style',
            ],
            'minio' => [
                'access_key' => 'minio_access_key',
                'secret_key' => 'minio_secret_key',
                'bucket' => 'minio_bucket',
                'url' => 'minio_url',
                'endpoint' => 'minio_endpoint',
                'region' => 'minio_region',
            ],
            'oss' => [
                'key' => 'oss_access_key',
                'secret' => 'oss_access_secret',
                'bucket' => 'oss_bucket',
                'endpoint' => 'oss_endpoint',
                'url' => 'oss_url',
                'region' => 'oss_region',
            ],
            'rustfs' => [
                'access_key' => 'rustfs_access_key',
                'secret_key' => 'rustfs_secret_key',
                'bucket' => 'rustfs_bucket',
                'url' => 'rustfs_url',
                'endpoint' => 'rustfs_endpoint',
                'region' => 'rustfs_region',
            ],
            default => [],
        };

        $config = [];
        foreach ($keyMap as $param => $configKey) {
            $value = $configService->getConfigValue($configKey);
            if ($value !== null && $value !== '') {
                $config[$param] = $value;
            }
        }

        return $config;
    }

    /**
     * 获取所有可用的驱动列表
     */
    public function getAvailableDrivers(): array
    {
        return [
            ['value' => 'local', 'label' => '本地存储'],
            ['value' => 's3', 'label' => 'S3存储'],
            ['value' => 'minio', 'label' => 'MinIO存储'],
            ['value' => 'oss', 'label' => '阿里云OSS'],
            ['value' => 'rustfs', 'label' => 'RustFS存储'],
        ];
    }

    /**
     * 测试当前驱动连接
     */
    public function testCurrentConnection(): bool
    {
        $driver = $this->getDefaultDriver();
        return $driver->testConnection();
    }

    /**
     * 测试指定驱动连接
     */
    public function testDriverConnection(string $driverName): bool
    {
        $driver = $this->getDriverByName($driverName);
        return $driver->testConnection();
    }
}
