<?php

namespace Modules\Order\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Modules\Business\Models\Employee;
use Modules\Order\Models\Route;

class RouteImport implements ToCollection, WithHeadingRow
{
    protected $successCount = 0;

    protected $errorCount = 0;

    protected $errors = [];

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            try {
                if (empty($row['name'])) {
                    continue;
                }

                $employeeId = null;
                if (! empty($row['employee_name']) || ! empty($row['employee_id'])) {
                    $employee = Employee::where('name', $row['employee_name'])
                        ->orWhere('id', $row['employee_id'])
                        ->first();
                    $employeeId = $employee ? $employee->id : null;
                }

                $route = Route::updateOrCreate(
                    ['code' => $row['code'] ?? null],
                    [
                        'name' => $row['name'],
                        'area' => $row['area'] ?? null,
                        'employee_id' => $employeeId,
                        'sort_order' => $row['sort_order'] ?? 0,
                        'remark' => $row['remark'] ?? null,
                    ]
                );

                // 如果有客户数据，关联到线路
                if (! empty($row['customer_ids'])) {
                    $customerIds = array_filter(explode(',', $row['customer_ids']));
                    foreach ($customerIds as $customerId) {
                        $route->customers()->attach($customerId, [
                            'visit_order' => $row['visit_order'] ?? 0,
                            'visit_frequency' => $row['visit_frequency'] ?? 1,
                        ]);
                    }
                }

                $this->successCount++;
            } catch (\Exception $e) {
                $this->errorCount++;
                $this->errors[] = '第 '.($index + 2).' 行: '.$e->getMessage();
            }
        }
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
