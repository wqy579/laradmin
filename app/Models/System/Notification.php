<?php

namespace App\Models\System;

use App\Traits\ModelTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use ModelTrait, SoftDeletes;

    protected $table = 'system_notification';

    protected $fillable = [
        'user_ids',
        'department_ids',
        'title',
        'content',
        'type',
        'category',
        'data',
        'action_data',
        'is_read',
        'read_at',
        'sent_via_websocket',
        'sent_at',
        'retry_count',
    ];

    protected $casts = [
        'user_ids' => 'array',
        'department_ids' => 'array',
        'data' => 'array',
        'action_data' => 'array',
        'is_read' => 'boolean',
        'sent_via_websocket' => 'boolean',
        'read_at' => 'datetime',
        'sent_at' => 'datetime',
        'retry_count' => 'integer',
    ];

    /**
     * 通知类型常量
     */
    const TYPE_INFO = 'info';
    const TYPE_SUCCESS = 'success';
    const TYPE_WARNING = 'warning';
    const TYPE_ERROR = 'error';
    const TYPE_TASK = 'task';
    const TYPE_SYSTEM = 'system';

    /**
     * 通知分类常量
     */
    const CATEGORY_SYSTEM = 'system';
    const CATEGORY_TASK = 'task';
    const CATEGORY_MESSAGE = 'message';
    const CATEGORY_REMINDER = 'reminder';
    const CATEGORY_ANNOUNCEMENT = 'announcement';

    /**
     * 操作类型常量
     */
    const ACTION_LINK = 'link';
    const ACTION_DOWNLOAD = 'download';
    const ACTION_MODAL = 'modal';
    const ACTION_NONE = 'none';

    /**
     * 获取通知目标用户ID列表
     * 包括直接指定的用户和部门下的所有用户
     */
    public function getTargetUserIds(): array
    {
        $userIds = $this->user_ids ?? [];

        // 获取部门下的所有用户
        if (!empty($this->department_ids)) {
            $departmentUsers = \App\Models\Auth\User::whereIn('department_id', $this->department_ids)
                ->where('status', 1)
                ->pluck('id')
                ->toArray();
            $userIds = array_merge($userIds, $departmentUsers);
        }

        return array_unique($userIds);
    }

    /**
     * 检查通知是否发给指定用户
     */
    public function isForUser(int $userId): bool
    {
        // 检查直接指定的用户
        if (in_array($userId, $this->user_ids ?? [])) {
            return true;
        }

        // 检查部门用户
        if (!empty($this->department_ids)) {
            $user = \App\Models\Auth\User::find($userId);
            if ($user && in_array($user->department_id, $this->department_ids)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 标记为已读
     */
    public function markAsRead(): bool
    {
        $this->is_read = true;
        $this->read_at = now();
        return $this->save();
    }

    /**
     * 标记为未读
     */
    public function markAsUnread(): bool
    {
        $this->is_read = false;
        $this->read_at = null;
        return $this->save();
    }

    /**
     * 标记已通过WebSocket发送
     */
    public function markAsSent(): bool
    {
        $this->sent_via_websocket = true;
        $this->sent_at = now();
        return $this->save();
    }

    /**
     * 增加重试次数
     */
    public function incrementRetry(): bool
    {
        $this->increment('retry_count');
        return true;
    }

    /**
     * 获取通知类型选项
     */
    public static function getTypeOptions(): array
    {
        return [
            self::TYPE_INFO => '信息',
            self::TYPE_SUCCESS => '成功',
            self::TYPE_WARNING => '警告',
            self::TYPE_ERROR => '错误',
            self::TYPE_TASK => '任务',
            self::TYPE_SYSTEM => '系统',
        ];
    }

    /**
     * 获取通知分类选项
     */
    public static function getCategoryOptions(): array
    {
        return [
            self::CATEGORY_SYSTEM => '系统通知',
            self::CATEGORY_TASK => '任务通知',
            self::CATEGORY_MESSAGE => '消息通知',
            self::CATEGORY_REMINDER => '提醒通知',
            self::CATEGORY_ANNOUNCEMENT => '公告通知',
        ];
    }

    /**
     * 获取操作类型选项
     */
    public static function getActionTypeOptions(): array
    {
        return [
            self::ACTION_LINK => '跳转链接',
            self::ACTION_MODAL => '弹窗显示',
            self::ACTION_NONE => '无操作',
        ];
    }
}
