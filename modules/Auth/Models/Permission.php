<?php

namespace Modules\Auth\Models;

use App\Traits\ModelTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Permission extends Model
{
    use ModelTrait, SoftDeletes;

    protected $table = 'auth_permission';

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
