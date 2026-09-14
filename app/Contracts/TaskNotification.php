<?php

namespace App\Contracts;

/**
 * 任务型通知端口（consumer-defined port）。
 *
 * 消费方：modules/Auth —— 用户导入/导出任务、个人信息变更
 * 实现方：modules/System —— Modules\System\Services\NotificationService
 * 绑定处：modules/System\Providers\SystemServiceProvider
 *
 * 为什么放在共享内核而不是某个模块：
 * 消费方 type-hint 内核契约，就不会在编译期依赖 Modules\System，从而保住
 * 「Auth 是底层身份模块、Business / System 单向依赖 Auth」的 DAG。
 * 若把契约放在 Auth 里、由 System 实现，System 就得反向引用 Auth，
 * 依赖方向反而倒过来，环只是换了个形状。
 *
 * 返回类型用 mixed 而不是 Notification 模型，避免契约反向依赖实现细节。
 * 本接口的常量是通知词汇表的唯一来源，Notification 模型的常量委托到这里。
 */
interface TaskNotification
{
    // 通知类型
    public const TYPE_INFO = 'info';
    public const TYPE_SUCCESS = 'success';
    public const TYPE_WARNING = 'warning';
    public const TYPE_ERROR = 'error';
    public const TYPE_TASK = 'task';
    public const TYPE_SYSTEM = 'system';

    // 通知分类
    public const CATEGORY_SYSTEM = 'system';
    public const CATEGORY_TASK = 'task';
    public const CATEGORY_MESSAGE = 'message';
    public const CATEGORY_REMINDER = 'reminder';
    public const CATEGORY_ANNOUNCEMENT = 'announcement';

    // 操作按钮类型
    public const ACTION_LINK = 'link';
    public const ACTION_DOWNLOAD = 'download';
    public const ACTION_MODAL = 'modal';
    public const ACTION_NONE = 'none';

    /**
     * 创建一条通知。
     *
     * @param array $payload 形状与 Notification 模型 fillable 一致：
     *                       user_ids / department_ids / title / content /
     *                       type / category / action_data / is_read 等
     *
     * ⚠️ 必须提供 user_ids 或 department_ids 之一（复数），
     * 否则实现方抛 InvalidArgumentException。单数 user_id 不被接受。
     */
    public function create(array $payload): mixed;

    /** 发送通知给单个用户。 */
    public function sendToUser(
        int $userId,
        string $title,
        string $content,
        string $type = self::TYPE_INFO,
        string $category = self::CATEGORY_SYSTEM,
        array $actionData = []
    ): mixed;

    /**
     * 近 $minutes 分钟内是否已存在同名、且目标包含该用户的通知。
     *
     * 供导出任务做幂等去重，避免重复导出同一份数据。
     */
    public function hasRecent(string $title, int $userId, int $minutes = 5): bool;
}
