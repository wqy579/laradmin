<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class GenericExport implements FromCollection, WithHeadings
{
    protected $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * 获取数据集合
     */
    public function collection(): \Illuminate\Support\Enumerable
    {
        return collect($this->data);
    }

    /**
     * 设置表头
     */
    public function headings(): array
    {
        // 第一行作为表头
        return $this->data[0] ?? [];
    }
}
