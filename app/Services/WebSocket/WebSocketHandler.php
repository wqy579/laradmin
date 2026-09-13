<?php

namespace App\Services\WebSocket;

use Hhxsv5\LaravelS\Swoole\WebSocketHandlerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * WebSocket 处理器
 *
 * 处理 WebSocket 连接事件：onOpen, onMessage, onClose
 */
class WebSocketHandler implements WebSocketHandlerInterface
{
    /**
     * WebSocketHandlerInterface 需要的构造函数
     * wsTable 直接从 handler 方法的 $server 参数中访问
     */
    public function __construct()
    {
        // 空构造函数 - wsTable 从 server 参数中访问
    }

    /**
     * 处理 WebSocket 握手（可选）
     *
     * @param Request $request 请求对象
     * @param Response $response 响应对象
     * @return void
     */
    // public function onHandShake(Request $request, Response $response)
    // {
    //     // 自定义握手逻辑（如果需要）
    //     // 握手成功后，onOpen 事件会自动触发
    // }

    /**
     * 处理连接打开事件
     *
     * @param Server $server WebSocket 服务器对象
     * @param Request $request 请求对象
     * @return void
     */
    public function onOpen(Server $server, Request $request): void
    {
        try {
            // 从服务器获取 wsTable
            $wsTable = $server->wsTable;

            // 从查询字符串获取 user_id 和 token
            $userId = (int)($request->get['user_id'] ?? 0);
            $token = $request->get['token'] ?? '';

            // 用户认证
            if (!$userId || !$token) {
                $this->safePush($server, $request->fd, json_encode([
                    'type' => 'error',
                    'data' => [
                        'message' => '认证失败：缺少 user_id 或 token',
                        'code' => 401
                    ]
                ]));
                $server->disconnect($request->fd);
                return;
            }

            // 验证 JWT token
            try {
                $payload = JWTAuth::setToken($token)->getPayload();

                // 验证 token 中的用户 ID 是否匹配
                $tokenUserId = $payload['sub'] ?? null;
                if ($tokenUserId != $userId) {
                    $this->safePush($server, $request->fd, json_encode([
                        'type' => 'error',
                        'data' => [
                            'message' => '认证失败：用户 ID 不匹配',
                            'code' => 401
                        ]
                    ]));
                    $server->disconnect($request->fd);
                    return;
                }

                // 验证 token 是否过期
                if (isset($payload['exp']) && $payload['exp'] < time()) {
                    $this->safePush($server, $request->fd, json_encode([
                        'type' => 'error',
                        'data' => [
                            'message' => '认证失败：token 已过期',
                            'code' => 401
                        ]
                    ]));
                    $server->disconnect($request->fd);
                    return;
                }
            } catch (\Exception $e) {
                $this->safePush($server, $request->fd, json_encode([
                    'type' => 'error',
                    'data' => [
                        'message' => '认证失败：无效的 token',
                        'code' => 401
                    ]
                ]));
                $server->disconnect($request->fd);
                return;
            }

            // 存储连接映射：uid:{userId} -> fd
            $wsTable->set('uid:' . $userId, [
                'value' => $request->fd,
                'expiry' => time() + 3600 // 1 小时过期
            ]);

            // 存储反向映射：fd:{fd} -> userId
            $wsTable->set('fd:' . $request->fd, [
                'value' => $userId,
                'expiry' => time() + 3600
            ]);

            // 发送欢迎消息
            $this->safePush($server, $request->fd, json_encode([
                'type' => 'connected',
                'data' => [
                    'message' => '欢迎连接到 LaravelS WebSocket',
                    'user_id' => $userId,
                    'fd' => $request->fd,
                    'timestamp' => time()
                ]
            ]));
        } catch (\Exception $e) {
            $this->safePush($server, $request->fd, json_encode([
                'type' => 'error',
                'data' => [
                    'message' => '连接错误：' . $e->getMessage(),
                    'code' => 500
                ]
            ]));
            $server->disconnect($request->fd);
        }
    }

    /**
     * 安全的推送消息（检查连接是否建立）
     *
     * @param Server $server WebSocket 服务器对象
     * @param int $fd 文件描述符
     * @param string $data 要发送的数据
     * @return bool 是否发送成功
     */
    protected function safePush(Server $server, int $fd, string $data): bool
    {
        try {
            if ($server->isEstablished($fd)) {
                return $server->push($fd, $data);
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 处理接收消息事件
     *
     * @param Server $server WebSocket 服务器对象
     * @param Frame $frame WebSocket 帧对象
     * @return void
     */
    public function onMessage(Server $server, Frame $frame): void
    {
        try {
            // 从服务器获取 wsTable
            $wsTable = $server->wsTable;

            // 从 fd 映射获取 user_id
            $fdInfo = $wsTable->get('fd:' . $frame->fd);
            if ($fdInfo === false) {
                $server->disconnect($frame->fd);
                return;
            }

            $userId = (int)$fdInfo['value'];

            // 解析消息
            $message = json_decode($frame->data, true);

            if (!$message || !isset($message['type'])) {
                $this->safePush($server, $frame->fd, json_encode([
                    'type' => 'error',
                    'data' => [
                        'message' => '无效的消息格式',
                        'code' => 400
                    ]
                ]));
                return;
            }

            $type = $message['type'];
            $data = $message['data'] ?? [];

            // 处理不同类型的消息
            switch ($type) {
                case 'ping':
                    // 响应 ping
                    $this->safePush($server, $frame->fd, json_encode([
                        'type' => 'pong',
                        'data' => $data
                    ]));
                    break;

                case 'heartbeat':
                    // 心跳确认
                    $this->safePush($server, $frame->fd, json_encode([
                        'type' => 'heartbeat_ack',
                        'data' => array_merge($data, [
                            'timestamp' => time()
                        ])
                    ]));
                    break;

                case 'chat':
                    // 私聊消息
                    $this->handleChatMessage($server, $wsTable, $frame, $userId, $data);
                    break;

                case 'broadcast':
                    // 广播消息给所有用户
                    $this->handleBroadcast($server, $wsTable, $userId, $data);
                    break;

                case 'subscribe':
                    // 订阅频道
                    $this->handleSubscribe($server, $wsTable, $frame, $userId, $data);
                    break;

                case 'unsubscribe':
                    // 取消订阅频道
                    $this->handleUnsubscribe($server, $wsTable, $frame, $userId, $data);
                    break;

                default:
                    // 未知消息类型
                    $this->safePush($server, $frame->fd, json_encode([
                        'type' => 'error',
                        'data' => [
                            'message' => '未知的消息类型：' . $type,
                            'code' => 400
                        ]
                    ]));
                    break;
            }
        } catch (\Exception $e) {
        }
    }

    /**
     * 处理连接关闭事件
     *
     * @param Server $server WebSocket 服务器对象
     * @param int $fd 文件描述符
     * @param int $reactorId 反应器 ID
     * @return void
     */
    public function onClose(Server $server, $fd, $reactorId): void
    {
        try {
            // 从服务器获取 wsTable
            $wsTable = $server->wsTable;

            // 从 fd 映射获取 user_id
            $fdInfo = $wsTable->get('fd:' . $fd);

            if ($fdInfo !== false) {
                $userId = (int)$fdInfo['value'];

                // 删除 uid 映射
                $wsTable->del('uid:' . $userId);

                // 删除该用户的所有频道订阅
                $this->removeUserFromAllChannels($wsTable, $userId, $fd);
            }

            // 删除 fd 映射
            $wsTable->del('fd:' . $fd);
        } catch (\Exception $e) {
        }
    }

    /**
     * 处理私聊消息
     *
     * @param Server $server WebSocket 服务器对象
     * @param \Swoole\Table $wsTable WebSocket 表
     * @param Frame $frame WebSocket 帧对象
     * @param int $fromUserId 发送者用户 ID
     * @param array $data 消息数据
     * @return void
     */
    protected function handleChatMessage(Server $server, \Swoole\Table $wsTable, Frame $frame, int $fromUserId, array $data): void
    {
        $toUserId = $data['to_user_id'] ?? 0;

        if (!$toUserId) {
            $this->safePush($server, $frame->fd, json_encode([
                'type' => 'error',
                'data' => [
                    'message' => '缺少 to_user_id',
                    'code' => 400
                ]
            ]));
            return;
        }

        // 获取接收者的 fd
        $recipientInfo = $wsTable->get('uid:' . $toUserId);

        if ($recipientInfo === false) {
            $this->safePush($server, $frame->fd, json_encode([
                'type' => 'error',
                'data' => [
                    'message' => '用户不在线',
                    'to_user_id' => $toUserId,
                    'code' => 404
                ]
            ]));
            return;
        }

        $toFd = (int)$recipientInfo['value'];

        // 发送消息给接收者
        $this->safePush($server, $toFd, json_encode([
            'type' => 'chat',
            'data' => array_merge($data, [
                'from_user_id' => $fromUserId,
                'timestamp' => time()
            ])
        ]));
    }

    /**
     * 处理广播消息
     *
     * @param Server $server WebSocket 服务器对象
     * @param \Swoole\Table $wsTable WebSocket 表
     * @param int $userId 用户 ID
     * @param array $data 消息数据
     * @return void
     */
    protected function handleBroadcast(Server $server, \Swoole\Table $wsTable, int $userId, array $data): void
    {
        $excludeUserId = $data['exclude_user_id'] ?? null;
        $message = json_encode([
            'type' => 'broadcast',
            'data' => array_merge($data, [
                'from_user_id' => $userId,
                'timestamp' => time()
            ])
        ]);

        // 发送消息给所有连接的用户
        foreach ($wsTable as $key => $row) {
            if (strpos($key, 'uid:') === 0) {
                $targetUserId = (int)substr($key, 4); // 移除 'uid:' 前缀
                $fd = (int)$row['value'];

                // 跳过排除的用户
                if ($excludeUserId && $targetUserId == $excludeUserId) {
                    continue;
                }

                $this->safePush($server, $fd, $message);
            }
        }
    }

    /**
     * 处理频道订阅
     *
     * @param Server $server WebSocket 服务器对象
     * @param \Swoole\Table $wsTable WebSocket 表
     * @param Frame $frame WebSocket 帧对象
     * @param int $userId 用户 ID
     * @param array $data 消息数据
     * @return void
     */
    protected function handleSubscribe(Server $server, \Swoole\Table $wsTable, Frame $frame, int $userId, array $data): void
    {
        $channel = $data['channel'] ?? '';

        if (!$channel) {
            $this->safePush($server, $frame->fd, json_encode([
                'type' => 'error',
                'data' => [
                    'message' => '缺少频道名称',
                    'code' => 400
                ]
            ]));
            return;
        }

        // 存储频道订阅
        $channelKey = 'channel:' . $channel . ':fd:' . $frame->fd;
        $wsTable->set($channelKey, [
            'value' => $userId,
            'expiry' => time() + 3600
        ]);

        $this->safePush($server, $frame->fd, json_encode([
            'type' => 'subscribed',
            'data' => [
                'channel' => $channel,
                'message' => '成功订阅频道：' . $channel,
                'timestamp' => time()
            ]
        ]));
    }

    /**
     * 处理频道取消订阅
     *
     * @param Server $server WebSocket 服务器对象
     * @param \Swoole\Table $wsTable WebSocket 表
     * @param Frame $frame WebSocket 帧对象
     * @param int $userId 用户 ID
     * @param array $data 消息数据
     * @return void
     */
    protected function handleUnsubscribe(Server $server, \Swoole\Table $wsTable, Frame $frame, int $userId, array $data): void
    {
        $channel = $data['channel'] ?? '';

        if (!$channel) {
            $this->safePush($server, $frame->fd, json_encode([
                'type' => 'error',
                'data' => [
                    'message' => '缺少频道名称',
                    'code' => 400
                ]
            ]));
            return;
        }

        // 删除频道订阅
        $channelKey = 'channel:' . $channel . ':fd:' . $frame->fd;
        $wsTable->del($channelKey);

        $this->safePush($server, $frame->fd, json_encode([
            'type' => 'unsubscribed',
            'data' => [
                'channel' => $channel,
                'message' => '成功取消订阅频道：' . $channel,
                'timestamp' => time()
            ]
        ]));
    }

    /**
     * 从所有频道中移除用户
     *
     * @param \Swoole\Table $wsTable WebSocket 表
     * @param int $userId 用户 ID
     * @param int $fd 文件描述符
     * @return void
     */
    protected function removeUserFromAllChannels(\Swoole\Table $wsTable, int $userId, int $fd): void
    {
        foreach ($wsTable as $key => $row) {
            if (strpos($key, 'channel:') === 0 && strpos($key, ':fd:' . $fd) !== false) {
                $wsTable->del($key);
            }
        }
    }
}
