<?php

namespace Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Stock\Exceptions\StockRuleException;
use Modules\Stock\Models\Product;
use Modules\Stock\Services\StockService;

class StockInController extends Controller
{
    public function __construct(private StockService $stocks) {}

    public function index(Request $request)
    {
        return response()->json($this->stocks->query($request));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty_large' => 'nullable|integer|min:0',
            'items.*.qty_medium' => 'nullable|integer|min:0',
            'items.*.qty_small' => 'nullable|integer|min:0',
            'items.*.price_large' => 'nullable|numeric|min:0',
            'items.*.price_medium' => 'nullable|numeric|min:0',
            'items.*.price_small' => 'nullable|numeric|min:0',
        ]);

        $errors = [];
        $results = [];
        DB::beginTransaction();
        try {
            foreach ($request->items as $itemData) {
                [$quantity, $amount, $price] = $this->computeItemQtyAmount($itemData);
                if ($quantity <= 0) {
                    continue;
                }
                try {
                    $results[] = $this->stocks->stockIn(
                        (int) $itemData['product_id'],
                        (int) $validated['warehouse_id'],
                        $quantity,
                        $price
                    );
                } catch (StockRuleException $e) {
                    $errors[] = $e->getMessage();
                }
            }
            if ($errors) {
                DB::rollBack();
                return response()->json(['message' => implode('；', $errors)], 422);
            }
            DB::commit();

            return response()->json(['data' => $results, 'message' => '入库成功']);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => '入库失败: '.$e->getMessage()], 500);
        }
    }

    /** 折算单行：三档数量/成本价折算 quantity；三档全 0 且传 quantity/price（旧契约）按旧口径 */
    private function computeItemQtyAmount(array $itemData): array
    {
        $qtyLarge = (int) ($itemData['qty_large'] ?? 0);
        $qtyMedium = (int) ($itemData['qty_medium'] ?? 0);
        $qtySmall = (int) ($itemData['qty_small'] ?? 0);
        $priceLarge = (float) ($itemData['price_large'] ?? 0);
        $priceMedium = (float) ($itemData['price_medium'] ?? 0);
        $priceSmall = (float) ($itemData['price_small'] ?? 0);

        $product = Product::find($itemData['product_id']);
        $c = (int) ($product?->unit_conversion ?? 0);
        $mc = (int) ($product?->unit_conversion_medium ?? 0);

        if ($qtyLarge === 0 && $qtyMedium === 0 && $qtySmall === 0 && isset($itemData['quantity'])) {
            $quantity = (int) $itemData['quantity'];
        } else {
            $quantity = $qtyLarge * $c + $qtyMedium * $mc + $qtySmall;
        }

        if ($priceLarge == 0 && $priceMedium == 0 && $priceSmall == 0 && isset($itemData['price'])) {
            $amount = round((float) $itemData['quantity'] * (float) $itemData['price'], 2);
            $price = (float) $itemData['price'];
        } else {
            $amount = round($qtyLarge * $priceLarge + $qtyMedium * $priceMedium + $qtySmall * $priceSmall, 2);
            $price = $priceSmall > 0 ? $priceSmall : (float) ($itemData['price'] ?? 0);
        }

        return [$quantity, $amount, $price];
    }
}
