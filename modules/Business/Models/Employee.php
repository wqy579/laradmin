<?php

namespace Modules\Business\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $table = 'employees';

    protected $fillable = [
        'code', 'name', 'gender', 'department', 'position',
        'salesman_code', 'phone', 'wechat_bound', 'id_card', 'hire_date',
        'base_salary', 'commission_rate', 'user_id', 'role', 'is_active', 'remark',
    ];

    protected $casts = [
        'wechat_bound' => 'boolean',
        'is_active' => 'boolean',
        'base_salary' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'hire_date' => 'date',
        'user_id' => 'integer',
    ];

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'employee_id');
    }
}
