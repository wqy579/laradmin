<?php

namespace Modules\Office\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 站内消息（消息通知中心）。
 *
 * 与 system_notification 的区别：本表按「每个收件人一条记录」存，user_id 直接
 * 落在行上，已读态是个人私有状态——A 点已读不影响 B。这正是铃铛未读角标与
 * 消息中心「未读/已读」双栏所必需的语义，与同模块 mail_recipients 同设计。
 * system_notification 用 user_ids JSON 数组 + 单一 is_read，无法表达该语义。
 */
class Message extends Model
{
    use SoftDeletes;

    protected $table = 'messages';

    protected $fillable = [
        'user_id', 'type', 'category', 'title', 'content',
        'payload', 'is_read', 'read_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    // 消息类型：决定前端左侧图标与业务含义
    public const TYPE_APPROVAL = 'approval';

    public const TYPE_STOCK_WARNING = 'stock_warning';

    public const TYPE_SYSTEM = 'system';

    public const TYPE_BUSINESS = 'business';

    /** 列表接口 status 参数取值 */
    public const STATUS_UNREAD = 'unread';

    public const STATUS_READ = 'read';

    public const STATUS_ALL = 'all';
}
