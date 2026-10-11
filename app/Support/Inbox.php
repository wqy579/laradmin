<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 站内消息写入助手（消息通知中心）。
 *
 * 跨模块写入 messages 表：订单/收款/盘点审核、装车确认、库存预警都要给指定用户
 * 写一条消息，而这些触发点散落在 Order / Stock / Delivery 三个模块里。与其为每个
 * 消费方都引 Modules\Office 的依赖（依赖方向会乱），不如像 App\Contracts\
 * TaskNotification 那样把「共享内核」的写入点收在这里——只操作 DB，不产生模块耦合。
 *
 * 消息表按「每个收件人一行」存（见 Modules\Office\Models\Message），所以 sendToUsers
 * 对每个 user_id 各插一行；已读态是个人私有的，互不影响。
 *
 * 容错：消息写入失败不能阻断主业务（审核通过不能因为写消息失败就回滚），
 * 所以所有方法内部 try/catch + Log::warning，永不抛异常。
 */
class Inbox
{
    // 消息类型（与 Modules\Office\Models\Message 常量一致）
    public const TYPE_APPROVAL = 'approval';

    public const TYPE_STOCK_WARNING = 'stock_warning';

    public const TYPE_SYSTEM = 'system';

    public const TYPE_BUSINESS = 'business';

    /**
     * 给指定用户各写一条消息。
     *
     * @param  array  $userIds  收件人 ID 列表（自动去空去重）
     * @param  array  $payload  ['type','category','title','content','payload'=>object]
     */
    public static function sendToUsers(array $userIds, string $title, string $content, string $type = self::TYPE_BUSINESS, ?string $category = null, array $data = []): int
    {
        $userIds = array_filter(array_unique(array_map('intval', $userIds)));
        $userIds = array_filter($userIds, fn ($id) => $id > 0);

        if (empty($userIds)) {
            return 0;
        }

        // 只给在册且未删除的用户写，避免给离职账号写一堆永远不会被看到的消息
        $validIds = DB::table('auth_user')
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->whereIn('id', $userIds)
            ->pluck('id')
            ->all();

        if (empty($validIds)) {
            return 0;
        }

        $now = now();
        $rows = array_map(function ($uid) use ($title, $content, $type, $category, $data, $now) {
            return [
                'user_id' => $uid,
                'type' => $type,
                'category' => $category,
                'title' => $title,
                'content' => $content,
                'payload' => empty($data) ? null : json_encode($data, JSON_UNESCAPED_UNICODE),
                'is_read' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $validIds);

        try {
            DB::table('messages')->insert($rows);

            return count($rows);
        } catch (\Throwable $e) {
            Log::warning('站内消息写入失败', [
                'type' => $type,
                'title' => $title,
                'user_count' => count($validIds),
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * 按权限码解析用户 ID——用来把「配货员/出纳/库管」这类岗位定位到具体人。
     *
     * 本系统没有独立的岗位字段，岗位由角色权限体现，所以用权限码反查用户：
     *   配货员 = delivery.pick / order.dispatch 持有者
     *   出纳   = finance.payment 持有者
     *   库管   = inventory.stock-check 持有者
     * 传多个权限码时取并集（任一命中即算该岗位）。
     *
     * @return int[]
     */
    public static function userIdsByPermissions(array $permissions): array
    {
        if (empty($permissions)) {
            return [];
        }

        return DB::table('auth_user as u')
            ->join('auth_user_role as ur', 'ur.user_id', '=', 'u.id')
            ->join('auth_role_permission as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('auth_permission as p', 'p.id', '=', 'rp.permission_id')
            ->where('u.status', 1)
            ->whereNull('u.deleted_at')
            ->whereIn('p.name', $permissions)
            ->distinct()
            ->pluck('u.id')
            ->all();
    }
}
