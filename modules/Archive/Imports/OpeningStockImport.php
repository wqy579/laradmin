<?php

namespace Modules\Archive\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Modules\Archive\Models\OpeningStock;
use Modules\Archive\Models\OpeningWizard;

/**
 * 期初库存导入（开账第 2 步）。
 *
 * 列序固定：商品编码 | 仓库编码 | 数量 | 成本单价（与 downloadTemplate('stock') 一致）。
 *
 * 为什么没用 WithHeadingRow：本项目 config/excel.php 里 imports.heading_row.formatter = 'slug'，
 * 而 Str::slug('商品编码') === ''（纯非 ASCII 会被 slug 清空），中文表头全部塌成同一个空键，
 * $row['商品编码'] 取不到任何东西——仓库里 UserImport 那批中文表头导入其实是静默失效的
 * （靠 empty() 判空把整表当成空行跳过）。所以这里按列序取整型下标，实测 keys=[0,1,2,3] 正常。
 * 表头行按首格内容识别后跳过：GenericExport 同时实现 WithHeadings + FromCollection，
 * 导出的模板文件里表头会出现两次，跳过而不是按位置硬跳，避免行号错位。
 */
class OpeningStockImport implements ToCollection
{
    /** 表头首格，用于跳过表头行 */
    protected const HEADING = '商品编码';

    protected int $successCount = 0;

    protected int $errorCount = 0;

    protected array $errors = [];

    protected array $unmatched = [];

    public function __construct(protected OpeningWizard $wizard) {}

    public function collection(Collection $rows): void
    {
        $rows = $rows->values();

        // 第一遍只收编码，批量查一次主数据，避免逐行 whereFirst
        $pending = [];
        $productCodes = [];
        $warehouseCodes = [];
        foreach ($rows as $index => $row) {
            $cells = array_map(fn ($v) => is_string($v) ? trim($v) : $v, array_values($row->toArray()));
            $no = $index + 1; // Excel 里用户看到的物理行号
            if ($this->isHeadingOrBlank($cells)) {
                continue;
            }
            $pending[] = ['no' => $no, 'cells' => $cells];
            $productCodes[] = (string) ($cells[0] ?? '');
            $warehouseCodes[] = (string) ($cells[1] ?? '');
        }

        $products = DB::table('products')->whereIn('code', array_unique($productCodes))->pluck('id', 'code');
        $warehouses = DB::table('warehouses')->whereIn('code', array_unique($warehouseCodes))->pluck('id', 'code');

        foreach ($pending as $item) {
            [$no, $cells] = [$item['no'], $item['cells']];
            $productCode = (string) ($cells[0] ?? '');
            $warehouseCode = (string) ($cells[1] ?? '');

            if ($productCode === '') {
                $this->addError($no, '商品编码不能为空');

                continue;
            }
            $productId = $products[$productCode] ?? null;
            if (! $productId) {
                $this->unmatched[] = "商品编码 {$productCode}";
                $this->addError($no, "商品编码不存在: {$productCode}");

                continue;
            }
            if ($warehouseCode === '') {
                $this->addError($no, '仓库编码不能为空');

                continue;
            }
            $warehouseId = $warehouses[$warehouseCode] ?? null;
            if (! $warehouseId) {
                $this->unmatched[] = "仓库编码 {$warehouseCode}";
                $this->addError($no, "仓库编码不存在: {$warehouseCode}");

                continue;
            }

            $quantity = $this->number($cells[2] ?? null);
            if ($quantity === null) {
                $this->addError($no, '数量必须是数字');

                continue;
            }
            if ($quantity < 0) {
                $this->addError($no, '期初数量不能为负数');

                continue;
            }
            $price = $this->number($cells[3] ?? null, true);
            if ($price === null) {
                $this->addError($no, '成本单价必须是数字');

                continue;
            }

            // 同商品同仓库覆盖式写入：重复导入同一份表要幂等，不能撞唯一键
            OpeningStock::updateOrCreate(
                ['wizard_id' => $this->wizard->id, 'product_id' => (int) $productId, 'warehouse_id' => (int) $warehouseId],
                [
                    'quantity' => (int) $quantity,
                    'cost_price' => round($price, 2),
                    'total_amount' => round((int) $quantity * round($price, 2), 2),
                    'source' => OpeningStock::SOURCE_IMPORT,
                ]
            );
            $this->successCount++;
        }
    }

    protected function isHeadingOrBlank(array $cells): bool
    {
        $first = $cells[0] ?? null;
        if ($first !== null && (string) $first !== '' && str_contains((string) $first, self::HEADING)) {
            return true;
        }

        return collect($cells)->every(fn ($v) => $v === null || $v === '');
    }

    /** 单元格转数字：允许千分位逗号与空格；空值按 0 或 null（$nullable）处理 */
    protected function number($value, bool $nullable = false): ?float
    {
        if ($value === null || $value === '') {
            return $nullable ? 0.0 : null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $clean = str_replace([',', ' ', '，'], '', (string) $value);

        return is_numeric($clean) ? (float) $clean : null;
    }

    protected function addError(int $row, string $message): void
    {
        $this->errorCount++;
        $this->errors[] = "第 {$row} 行: {$message}";
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    /** @return string[] */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** 未能匹配到主数据的编码，前端单独列一块方便回去补档案 */
    public function getUnmatched(): array
    {
        return array_values(array_unique($this->unmatched));
    }
}
