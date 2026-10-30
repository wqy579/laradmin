<?php

namespace Modules\Archive\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Modules\Archive\Models\OpeningBalance;
use Modules\Archive\Models\OpeningWizard;

/**
 * 期初余额导入（开账第 3 步）。
 *
 * 列序固定：科目编码 | 期初借方 | 期初贷方 | 年初借方 | 年初贷方
 * （与 downloadTemplate('balance') 一致）。
 *
 * 不用 WithHeadingRow 的原因与 OpeningStockImport 相同（中文表头被 slug 成空键），
 * 那份的注释里有完整说明，此处不重复。
 */
class OpeningBalanceImport implements ToCollection
{
    protected const HEADING = '科目编码';

    protected int $successCount = 0;

    protected int $errorCount = 0;

    protected array $errors = [];

    protected array $unmatched = [];

    public function __construct(protected OpeningWizard $wizard) {}

    public function collection(Collection $rows): void
    {
        $rows = $rows->values();

        $pending = [];
        $codes = [];
        foreach ($rows as $index => $row) {
            $cells = array_map(fn ($v) => is_string($v) ? trim($v) : $v, array_values($row->toArray()));
            $no = $index + 1;
            if ($this->isHeadingOrBlank($cells)) {
                continue;
            }
            $pending[] = ['no' => $no, 'cells' => $cells];
            $codes[] = (string) ($cells[0] ?? '');
        }

        // ledger_subjects 的 code 唯一，直接按编码取；is_leaf 一起带回来，非叶子要拒绝
        $subjects = DB::table('ledger_subjects')
            ->whereIn('code', array_unique($codes))
            ->get(['id', 'code', 'name', 'is_leaf'])
            ->keyBy('code');

        foreach ($pending as $item) {
            [$no, $cells] = [$item['no'], $item['cells']];
            $code = (string) ($cells[0] ?? '');

            if ($code === '') {
                $this->addError($no, '科目编码不能为空');

                continue;
            }
            $subject = $subjects[$code] ?? null;
            if (! $subject) {
                $this->unmatched[] = "科目编码 {$code}";
                $this->addError($no, "科目编码不存在: {$code}");

                continue;
            }
            if (! $subject->is_leaf) {
                $this->addError($no, "科目「{$subject->name}」不是末级科目，不能录入期初余额");

                continue;
            }

            $values = [];
            foreach ([1 => '期初借方', 2 => '期初贷方', 3 => '年初借方', 4 => '年初贷方'] as $col => $label) {
                $num = $this->number($cells[$col] ?? null);
                if ($num === null) {
                    $this->addError($no, "{$label}必须是数字");
                    $values = [];

                    break;
                }
                if ($num < 0) {
                    $this->addError($no, "{$label}不能为负数");
                    $values = [];

                    break;
                }
                $values[] = round($num, 2);
            }
            if (count($values) !== 4) {
                continue;
            }

            OpeningBalance::updateOrCreate(
                ['wizard_id' => $this->wizard->id, 'subject_id' => (int) $subject->id],
                [
                    'period' => $this->wizard->period,
                    'opening_debit' => $values[0],
                    'opening_credit' => $values[1],
                    'year_debit' => $values[2],
                    'year_credit' => $values[3],
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

    protected function number($value): ?float
    {
        if ($value === null || $value === '') {
            return 0.0;
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

    public function getUnmatched(): array
    {
        return array_values(array_unique($this->unmatched));
    }
}
