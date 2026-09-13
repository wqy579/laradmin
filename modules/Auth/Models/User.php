<?php

namespace Modules\Auth\Models;

use App\Traits\ModelTrait;
use Modules\Auth\Services\PermissionCacheService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Cache;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use ModelTrait, SoftDeletes;

    protected $table = 'auth_user';

    protected $fillable = [
        'username',
        'password',
        'real_name',
        'email',
        'phone',
        'department_id',
        'avatar',
        'status',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'deleted_at',
    ];

    protected $casts = [
        'status' => 'integer',
        'department_id' => 'integer',
        'last_login_at' => 'datetime',
    ];

    protected $with = ['roles.permissions'];

    protected $permissionCodes = null;

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'auth_user_role', 'user_id', 'role_id')
            ->withTimestamps();
    }

    public function permissions()
    {
        return $this->roles()->with('permissions');
    }

    public function hasPermission(string $permissionCode): bool
    {
        if ($this->permissionCodes === null) {
            $this->permissionCodes = $this->getPermissionCodes();
        }

        return in_array($permissionCode, $this->permissionCodes);
    }

    public function hasAnyPermission(array $permissionCodes): bool
    {
        foreach ($permissionCodes as $code) {
            if ($this->hasPermission($code)) {
                return true;
            }
        }
        return false;
    }

    public function hasAllPermissions(array $permissionCodes): bool
    {
        foreach ($permissionCodes as $code) {
            if (!$this->hasPermission($code)) {
                return false;
            }
        }
        return true;
    }

    public function getPermissionCodes(): array
    {
        $cacheKey = "user:{$this->id}:permission_codes";
        
        return Cache::remember($cacheKey, now()->addMinutes(60), function () {
            $codes = [];
            foreach ($this->roles as $role) {
                foreach ($role->permissions as $permission) {
                    $codes[] = $permission->name;
                }
            }
            return array_unique($codes);
        });
    }

    public function hasRole(string $roleCode): bool
    {
        return $this->roles()->where('code', $roleCode)->exists();
    }

    public function hasAnyRole(array $roleCodes): bool
    {
        return $this->roles()->whereIn('code', $roleCodes)->exists();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isActive(): bool
    {
        return $this->status === 1;
    }

    public function clearPermissionCache(): void
    {
        Cache::forget("user:{$this->id}:permission_codes");
        Cache::forget("user:{$this->id}:permissions");
        Cache::forget("user:{$this->id}:menu_tree");
        $this->permissionCodes = null;
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    protected static function boot()
    {
        parent::boot();

        static::updated(function ($user) {
            if ($user->isDirty(['status'])) {
                $user->clearPermissionCache();
            }
        });

        static::deleted(function ($user) {
            $user->clearPermissionCache();
        });
    }
}
