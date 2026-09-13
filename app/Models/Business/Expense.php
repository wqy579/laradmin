<?php

namespace App\Models\Business;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    protected $table = 'expenses';
    protected $fillable = [
        'expense_no', 'expense_type', 'amount', 'expense_date',
        'handler_id', 'department_id', 'remark', 'status',
    ];
    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function handler(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'handler_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
