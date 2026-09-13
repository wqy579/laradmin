<?php

namespace Modules\Auth\Services;

use Modules\Auth\Models\User;
use Illuminate\Support\Facades\Cache;

class UserOnlineService
{
    protected $cachePrefix = 'user_online:';
    protected $expireMinutes = 5;

    /**
     * 设置用户在线
     */
    public function setOnline(int $userId, string $token): void
    {
        $key = $this->getCacheKey($userId, $token);
        Cache::put($key, [
            'user_id' => $userId,
            'token' => $token,
            'last_active_at' => now()->toDateTimeString(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ], now()->addMinutes($this->expireMinutes));

        // 更新用户的最后在线时间
        User::where('id', $userId)->update([
            'last_active_at' => now(),
        ]);
    }

    /**
     * 更新用户在线状态
     */
    public function updateOnline(int $userId, string $token): void
    {
        $key = $this->getCacheKey($userId, $token);
        if (Cache::has($key)) {
            Cache::put($key, [
                'user_id' => $userId,
                'token' => $token,
                'last_active_at' => now()->toDateTimeString(),
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ], now()->addMinutes($this->expireMinutes));
        }
    }

    /**
     * 设置用户离线
     */
    public function setOffline(int $userId, string $token): void
    {
        $key = $this->getCacheKey($userId, $token);
        Cache::forget($key);
    }

    /**
     * 设置用户所有设备离线
     */
    public function setAllOffline(int $userId): void
    {
        $pattern = $this->cachePrefix . $userId . ':*';
        $keys = Cache::store('redis')->getPrefix() . $pattern;

        // Redis 模式删除
        if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
            $redis = Cache::getStore()->connection();
            $keys = $redis->keys($this->cachePrefix . $userId . ':*');
            if (!empty($keys)) {
                $redis->del($keys);
            }
        }
    }

    /**
     * 检查用户是否在线
     */
    public function isOnline(int $userId): bool
    {
        $pattern = $this->cachePrefix . $userId . ':*';

        if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
            $redis = Cache::getStore()->connection();
            $keys = $redis->keys($this->cachePrefix . $userId . ':*');
            return !empty($keys);
        }

        return false;
    }

    /**
     * 获取用户在线信息
     */
    public function getOnlineInfo(int $userId, string $token): ?array
    {
        $key = $this->getCacheKey($userId, $token);
        return Cache::get($key);
    }

    /**
     * 获取用户所有在线会话
     */
    public function getUserSessions(int $userId): array
    {
        $sessions = [];

        if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
            $redis = Cache::getStore()->connection();
            $keys = $redis->keys($this->cachePrefix . $userId . ':*');

            foreach ($keys as $key) {
                $session = $redis->get($key);
                if ($session) {
                    $sessions[] = json_decode($session, true);
                }
            }
        }

        return $sessions;
    }

    /**
     * 获取所有在线用户数量
     */
    public function getOnlineCount(): int
    {
        $count = 0;

        if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
            $redis = Cache::getStore()->connection();
            $keys = $redis->keys($this->cachePrefix . '*');
            // 去重用户ID
            $userIds = [];
            foreach ($keys as $key) {
                preg_match('/user_online:(\d+):/', $key, $matches);
                if (isset($matches[1])) {
                    $userIds[$matches[1]] = true;
                }
            }
            $count = count($userIds);
        }

        return $count;
    }

    /**
     * 获取在线用户列表
     */
    public function getOnlineUsers(int $limit = 100): array
    {
        $onlineUsers = [];

        if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
            $redis = Cache::getStore()->connection();
            $keys = $redis->keys($this->cachePrefix . '*');

            $userIds = [];
            $userSessions = [];

            foreach ($keys as $key) {
                $session = $redis->get($key);
                if ($session) {
                    $session = json_decode($session, true);
                    $userId = $session['user_id'];
                    if (!isset($userIds[$userId])) {
                        $userIds[$userId] = $userId;
                    }
                    $userSessions[$userId] = $session;
                }
            }

            $userIdList = array_values(array_slice($userIds, 0, $limit));
            $users = User::whereIn('id', $userIdList)->get();

            foreach ($users as $user) {
                $onlineUsers[] = [
                    'id' => $user->id,
                    'username' => $user->username,
                    'real_name' => $user->real_name,
                    'avatar' => $user->avatar,
                    'last_active_at' => $userSessions[$user->id]['last_active_at'] ?? null,
                    'ip' => $userSessions[$user->id]['ip'] ?? null,
                ];
            }
        }

        return $onlineUsers;
    }

    /**
     * 清理过期会话
     */
    public function cleanExpiredSessions(): int
    {
        // Redis 的 TTL 会自动清理过期键
        return 0;
    }

    /**
     * 生成缓存键
     */
    protected function getCacheKey(int $userId, string $token): string
    {
        return $this->cachePrefix . $userId . ':' . md5($token);
    }
}
