<?php

namespace Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Stock\Models\Stock;
use Modules\Stock\Services\StockService;

class StockController extends Controller
{
    public function __construct(private StockService $stocks) {}

    public function index(Request $request)
    {
        return response()->json($this->stocks->query($request));
    }

    public function show(Stock $stock)
    {
        $stock->load(['product', 'warehouse']);

        return response()->json(['data' => $stock]);
    }

    public function statistics()
    {
        return response()->json($this->stocks->statistics());
    }

    /** 库存查询导出（QA-13）：与列表同一套筛选，CSV 带 BOM，列与页面一致 */
    public function export(Request $request)
    {
        $rows = $this->stocks->queryForExport($request);

        $csv = "\u{FEFF}"; // BOM：Excel 正确识别 UTF-8
        $csv .= "库存查询\n\n";
        $csv .= "库存ID,商品编码,商品名称,规格,仓库,库存数量,冻结数量,成本单价,库存金额\n";

        foreach ($rows as $r) {
            $csv .= sprintf(
                "%d,%s,%s,%s,%s,%d,%d,%s,%s\n",
                $r->id,
                $this->csvCell((string) ($r->product?->code ?? '')),
                $this->csvCell((string) ($r->product?->name ?? '')),
                $this->csvCell((string) ($r->product?->spec ?? '')),
                $this->csvCell((string) ($r->warehouse?->name ?? '')),
                (int) $r->quantity,
                (int) $r->frozen_qty,
                number_format((float) $r->cost_price, 2, '.', ''),
                number_format((float) $r->total_amount, 2, '.', '')
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="stocks.csv"',
        ]);
    }

    /** CSV 单元格：含逗号/引号/换行时按 RFC4180 转义 */
    private function csvCell(string $value): string
    {
        if (preg_match('/[",\n\r]/', $value)) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
