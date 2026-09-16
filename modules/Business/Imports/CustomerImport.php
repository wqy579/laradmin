<?php

namespace Modules\Business\Imports;

use Modules\Business\Models\Customer;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class CustomerImport implements ToCollection, WithHeadingRow
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

                Customer::updateOrCreate(
                    ['code' => $row['code'] ?? null],
                    [
                        'name' => $row['name'],
                        'category' => $row['category'] ?? null,
                        'contact' => $row['contact'] ?? null,
                        'phone' => $row['phone'] ?? null,
                        'address' => $row['address'] ?? null,
                        'route' => $row['route'] ?? null,
                        'credit_limit' => $row['credit_limit'] ?? 0,
                        'balance' => $row['balance'] ?? 0,
                        'level' => $row['level'] ?? 1,
                        'remark' => $row['remark'] ?? null,
                    ]
                );

                $this->successCount++;
            } catch (\Exception $e) {
                $this->errorCount++;
                $this->errors[] = "第 " . ($index + 2) . " 行: " . $e->getMessage();
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
