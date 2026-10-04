<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\ProductBom;

/**
 * 商品档案的 BOM 配置：读写一个商品的 assembly/split 两套物料清单。
 * 路由挂在 business/product/{id}/bom，由商品编辑弹窗的「BOM 配置」tab 调用。
 */
class ProductBomController extends Controller
{
    use ResponseTrait;

    /** GET /business/product/{id}/bom */
    public function show($id)
    {
        $boms = ProductBom::with(['items'])
            ->where('product_id', $id)
            ->get()
            ->keyBy('type');

        return $this->success([
            'assembly' => $this->formatBom($boms->get('assembly')),
            'split' => $this->formatBom($boms->get('split')),
        ]);
    }

    /** PUT /business/product/{id}/bom  body: { assembly?: [{product_id,unit_usage,unit_cost}], split?: [...] } */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'assembly' => 'nullable|array',
            'assembly.*.product_id' => 'required|exists:products,id',
            'assembly.*.unit_usage' => 'required|numeric|min:0.001',
            'assembly.*.unit_cost' => 'nullable|numeric|min:0',
            'split' => 'nullable|array',
            'split.*.product_id' => 'required|exists:products,id',
            'split.*.unit_usage' => 'required|numeric|min:0.001',
            'split.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($id, $validated) {
            foreach (['assembly', 'split'] as $type) {
                $items = $validated[$type] ?? [];
                $bom = ProductBom::firstOrCreate(
                    ['product_id' => $id, 'type' => $type],
                    ['name' => $type === 'assembly' ? '组装BOM' : '拆分BOM']
                );
                DB::table('product_bom_items')->where('bom_id', $bom->id)->delete();
                foreach ($items as $item) {
                    $p = DB::table('products')->where('id', $item['product_id'])->first();
                    DB::table('product_bom_items')->insert([
                        'bom_id' => $bom->id,
                        'product_id' => $item['product_id'],
                        'product_code' => $p?->code ?? '',
                        'product_name' => $p?->name ?? '',
                        'unit_usage' => $item['unit_usage'],
                        'unit_cost' => $item['unit_cost'] ?? 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        return $this->success(null, 'BOM 配置已保存');
    }

    private function formatBom(?ProductBom $bom): array
    {
        if (! $bom) {
            return ['bom_id' => null, 'items' => []];
        }

        return [
            'bom_id' => $bom->id,
            'items' => $bom->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_code' => $i->product_code,
                'product_name' => $i->product_name,
                'unit_usage' => (float) $i->unit_usage,
                'unit_cost' => (float) $i->unit_cost,
            ])->values(),
        ];
    }
}
