<?php

namespace App\Models\Auth;

use App\Traits\ModelTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use ModelTrait, SoftDeletes;

    protected $table = 'auth_department';

    protected $fillable = [
        'name',
        'parent_id',
        'leader',
        'phone',
        'sort',
        'status',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'sort' => 'integer',
        'status' => 'integer',
    ];

    /**
     * 获取子部门
     */
    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_id');
    }

    /**
     * 获取父部门
     */
    public function parent()
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    /**
     * 获取部门下的用户
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'department_id');
    }
}
