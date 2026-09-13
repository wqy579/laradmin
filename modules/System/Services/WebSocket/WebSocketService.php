<?php

namespace Modules\System\Services\WebSocket;

use Swoole\WebSocket\Server;

/**
 * WebSocket 服务
 *
 * 提供 WebSocket 操作的便捷方法
 */
class WebSocketService
{
    /**
     * 获取 Swoole Server 实例
     *
     * @return Server
     */
    protected function getServer(): Server
    {
        /** @var Server $server */
        $server = app('swoole');
        return $server;
    }

    /**
     * 获取 WebSocket 表
     *
     * @return \Swoole\Table
     */
    protected function getWsTable(): \Swoole\Table
    {
        return app('swoole')->wsTable;
    }

    /**
     * 发送消息给指定用户
     *
     * @param int $userId 用户 ID
     * @param array $data 消息数据
     * @return bool
     */
    public function sendToUser(int $userId, array $data): bool
    {
        try {
            $wsTable = $this->getWsTable();
            $server = $this->getServer();

            // 获取用户的 fd
            $fdInfo = $wsTable->get('uid:' . $userId);

            if ($fdInfo === false) {
                return false;
            }

            $fd = (int)$fdInfo['value'];

            // 检查连接是否仍然建立
            if (!$server->isEstablished($fd)) {
                // 删除过期连接
                $wsTable->del('uid:' . $userId);
                $wsTable->del('fd:' . $fd);
                return false;
            }

            // 发送消息
            $result = $server->push($fd, json_encode($data));

            return $result;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 发送消息给多个用户
     *
     * @param array $userIds 用户 ID 数组
     * @param array $data 消息数据
     * @return array 成功发送的用户 ID 数组
     */
    public function sendToUsers(array $userIds, array $data): array
    {
        $sentTo = [];

        foreach ($userIds as $userId) {
            if ($this->sendToUser($userId, $data)) {
                $sentTo[] = $userId;
            }
        }

        return $sentTo;
    }

    /**
     * 广播消息给所有用户
     *
     * @param array $data 消息数据
     * @param int|null $excludeUserId 要排除的用户 ID
     * @return int 成功发送的用户数量
     */
    public function broadcast(array $data, ?int $excludeUserId = null): int
    {
        try {
            $wsTable = $this->getWsTable();
            $server = $this->getServer();

            $message = json_encode($data);
            $count = 0;

            foreach ($wsTable as $key => $row) {
                // 只处理用户映射（uid:*）
                if (strpos($key, 'uid:') !== 0) {
                    continue;
                }
                $userId = (int)substr($key, 4); // 移除 'uid:' 前缀
                $fd = (int)$row['value'];

                // 跳过排除的用户
                if ($excludeUserId && $userId == $excludeUserId) {
                    continue;
                }

                // 检查连接是否已建立并发送
                if ($server->isEstablished($fd)) {
                    if ($server->push($fd, $message)) {
                        $count++;
                    }
                } else {
                    // 删除过期连接
                    $wsTable->del('uid:' . $userId);
                    $wsTable->del('fd:' . $fd);
                }
            }

            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * 发送消息到频道
     *
     * @param string $channel 频道名称
     * @param array $data 消息数据
     * @return int 成功发送的订阅者数量
     */
    public function sendToChannel(string $channel, array $data): int
    {
        try {
            $wsTable = $this->getWsTable();
            $server = $this->getServer();

            $message = json_encode($data);
            $count = 0;
            $channelPrefix = 'channel:' . $channel . ':fd:';

            foreach ($wsTable as $key => $row) {
                // 只处理该频道的订阅
                if (strpos($key, $channelPrefix) !== 0) {
                    continue;
                }

                $fd = (int)substr($key, strlen($channelPrefix));

                // 检查连接是否已建立并发送
                if ($server->isEstablished($fd)) {
                    if ($server->push($fd, $message)) {
                        $count++;
                    }
                } else {
                    // 删除过期订阅
                    $wsTable->del($key);
                }
            }

            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * 获取在线用户数量
     *
     * @return int
     */
    public function getOnlineUserCount(): int
    {
        try {
            $wsTable = $this->getWsTable();
            $count = 0;

            foreach ($wsTable as $key => $row) {
                if (strpos($key, 'uid:') === 0) {
                    $count++;
                }
            }

            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * 检查用户是否在线
     *
     * @param int $userId 用户 ID
     * @return bool
     */
    public function isUserOnline(int $userId): bool
    {
        try {
            $wsTable = $this->getWsTable();
            $fdInfo = $wsTable->get('uid:' . $userId);

            if ($fdInfo === false) {
                return false;
            }

            $server = $this->getServer();
            $fd = (int)$fdInfo['value'];

            return $server->isEstablished($fd);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 获取在线用户 ID 列表
     *
     * @return array
     */
    public function getOnlineUserIds(): array
    {
        try {
            $wsTable = $this->getWsTable();
            $userIds = [];

            foreach ($wsTable as $key => $row) {
                if (strpos($key, 'uid:') === 0) {
                    $userId = (int)substr($key, 4); // 移除 'uid:' 前缀
                    $userIds[] = $userId;
                }
            }

            return $userIds;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 断开用户 WebSocket 连接
     *
     * @param int $userId 用户 ID
     * @return bool
     */
    public function disconnectUser(int $userId): bool
    {
        try {
            $wsTable = $this->getWsTable();
            $server = $this->getServer();

            // 获取用户的 fd
            $fdInfo = $wsTable->get('uid:' . $userId);

            if ($fdInfo === false) {
                return false;
            }

            $fd = (int)$fdInfo['value'];

            // 断开连接
            $server->disconnect($fd);

            // 删除映射
            $wsTable->del('uid:' . $userId);
            $wsTable->del('fd:' . $fd);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 发送系统通知
     *
     * @param string $title 标题
     * @param string $message 消息内容
     * @param string $type 类型
     * @param array $extraData 额外数据
     * @return int 成功发送的用户数量
     */
    public function sendSystemNotification(
        string $title,
        string $message,
        string $type = 'info',
        array $extraData = []
    ): int {
        $data = [
            'type' => 'notification',
            'data' => [
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'data' => $extraData,
                'timestamp' => time()
            ]
        ];

        return $this->broadcast($data);
    }

    /**
     * 发送通知给指定用户
     *
     * @param array $userIds 用户 ID 数组
     * @param string $title 标题
     * @param string $message 消息内容
     * @param string $type 类型
     * @param array $extraData 额外数据
     * @return int 成功发送的用户数量
     */
    public function sendNotificationToUsers(
        array $userIds,
        string $title,
        string $message,
        string $type = 'info',
        array $extraData = []
    ): int {
        $data = [
            'type' => 'notification',
            'data' => [
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'data' => $extraData,
                'timestamp' => time()
            ]
        ];

        $sentTo = $this->sendToUsers($userIds, $data);
        return count($sentTo);
    }

    /**
     * 推送数据更新
     *
     * @param array $userIds 用户 ID 数组
     * @param string $resourceType 资源类型
     * @param string $action 操作
     * @param array $data 数据
     * @return array 成功推送的用户 ID 数组
     */
    public function pushDataUpdate(
        array $userIds,
        string $resourceType,
        string $action,
        array $data
    ): array {
        $message = [
            'type' => 'data_update',
            'data' => [
                'resource_type' => $resourceType,
                'action' => $action,
                'data' => $data,
                'timestamp' => time()
            ]
        ];

        return $this->sendToUsers($userIds, $message);
    }

    /**
     * 推送数据更新到频道
     *
     * @param string $channel 频道名称
     * @param string $resourceType 资源类型
     * @param string $action 操作
     * @param array $data 数据
     * @return int 成功推送的订阅者数量
     */
    public function pushDataUpdateToChannel(
        string $channel,
        string $resourceType,
        string $action,
        array $data
    ): int {
        $message = [
            'type' => 'data_update',
            'data' => [
                'resource_type' => $resourceType,
                'action' => $action,
                'data' => $data,
                'timestamp' => time()
            ]
        ];

        return $this->sendToChannel($channel, $message);
    }
}
