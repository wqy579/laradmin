<?php

namespace App\Models\Business;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $table = 'attendances';

    protected $fillable = [
        'employee_id', 'date', 'clock_in', 'clock_in_lat', 'clock_in_lng', 'clock_in_address',
        'clock_out', 'clock_out_lat', 'clock_out_lng', 'status', 'visit_count', 'remark',
    ];

    protected $casts = [
        'date' => 'date',
        'clock_in' => 'datetime',
        'clock_out' => 'datetime',
        'clock_in_lat' => 'decimal:7',
        'clock_in_lng' => 'decimal:7',
        'clock_out_lat' => 'decimal:7',
        'clock_out_lng' => 'decimal:7',
        'status' => 'integer',
        'visit_count' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
