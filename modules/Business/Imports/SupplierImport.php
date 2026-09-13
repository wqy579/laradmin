<?php

namespace Modules\Business\Imports;

use Modules\Business\Models\Supplier;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class SupplierImport implements ToCollection, WithHeadingRow
{
    protected $successCount = 0;
    protected $errorCount = 0;
    protected $errors = [];

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            try {
                if (empty($row['name'])) {
                    continue;
                }

                Supplier::updateOrCreate(
                    ['code' => $row['code'] ?? null],
                    [
                        'name' => $row['name'],
                        'contact' => $row['contact'] ?? null,
                        'phone' => $row['phone'] ?? null,
                        'address' => $row['address'] ?? null,
                        'bank_name' => $row['bank_name'] ?? null,
                        'bank_account' => $row['bank_account'] ?? null,
                        'balance' => $row['balance'] ?? 0,
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
