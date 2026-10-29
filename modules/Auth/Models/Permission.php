<?php

namespace Modules\Auth\Models;

use App\Traits\ModelTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

class Permission extends Model
{
    use ModelTrait, SoftDeletes;

    protected $table = 'auth_permission';

    /**
     * 菜单 name 治理（F8 菜单治理任务书）。
     *
     * 前端 SPA 用 menu.name 注册路由：重复 name 会覆盖前一个路由，非英文 name
     * 会让路由名不合法——两种都表现为"点开某菜单白屏"。菜单 name 因此必须
     * 全局唯一且为英文。
     *
     * 校验时机与作用域：
     *  - 只对 type='menu' 生效（button/配置项不受限，它们不是路由）；
     *  - 只在 name 被"写入"时检查（isDirty）——firstOrCreate 命中已有行时不会
     *    save，天然跳过，种子因此保持可重跑（幂等）；这是"同一次运行内重复声明
     *    报错"而非"历史数据报错"；
     *  - 更新路径改 name 撞上别的行同样报错（keyNot self）。
     *
     * 走 DB::table 的种子（Cash/Ledger 的 by-name upsert）绕过模型钩子，它们不在此
     * 保护范围内——但那些种子自身不产生重复 name，且由 MenuNameGovernanceTest
     * 在整库种子后兜底断言唯一性。
     */
    protected static function booted(): void
    {
        static::saving(function (self $permission) {
            if ($permission->type !== 'menu' || ! $permission->isDirty('name')) {
                return;
            }

            $name = (string) $permission->name;
            if ($name === '' || ! preg_match('/^[a-zA-Z0-9._:-]+$/', $name)) {
                throw new RuntimeException(
                    "菜单 name「{$name}」非法：菜单 name 必须为英文（仅字母/数字/._:-），否则 SPA 路由注册白屏。"
                );
            }

            $query = static::query()
                ->where('name', $name)
                ->whereNull('deleted_at');

            // 新建（creating）时 getKey() 为 null：whereKeyNot(null) 会生成
            // `id != NULL`，SQL 里恒为 UNKNOWN，永远匹配不到——重复检测失效。
            // 所以仅更新路径才排除自身，新建路径直接按 name 查即可。
            if ($permission->exists) {
                $query->whereKeyNot($permission->getKey());
            }

            if ($query->exists()) {
                throw new RuntimeException(
                    "菜单 name「{$name}」重复：SPA 按 name 注册路由，重复会覆盖前一个路由导致白屏。"
                );
            }
        });
    }

    protected $fillable = [
        'title',
        'name',
        'type',
        'parent_id',
        'path',
        'component',
        'meta',
        'sort',
        'status',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'meta' => 'array',
        'sort' => 'integer',
        'status' => 'integer',
    ];

    /**
     * 关联角色
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'auth_role_permission', 'permission_id', 'role_id')
            ->withTimestamps();
    }

    /**
     * 获取子权限
     */
    public function children()
    {
        return $this->hasMany(Permission::class, 'parent_id');
    }

    /**
     * 获取父权限
     */
    public function parent()
    {
        return $this->belongsTo(Permission::class, 'parent_id');
    }
}
