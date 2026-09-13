<?php

namespace App\Models\Auth;

use App\Traits\ModelTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use ModelTrait, SoftDeletes;

    protected $table = 'auth_role';

    protected $fillable = [
        'name',
        'code',
        'description',
        'sort',
        'status',
    ];

    protected $casts = [
        'sort' => 'integer',
        'status' => 'integer',
    ];

    /**
     * 关联权限
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'auth_role_permission', 'role_id', 'permission_id')
            ->withTimestamps();
    }

    /**
     * 关联用户
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'auth_user_role', 'role_id', 'user_id')
            ->withTimestamps();
    }
}
