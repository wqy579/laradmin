<?php

namespace App\Http\Controllers\Admin\Business;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Services\Business\StockService;
use Illuminate\Http\Request;

class StockInController extends Controller
{
    public function __construct(private StockService $stocks)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->stocks->query($request));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'quantity' => 'required|integer|min:1',
            'cost_price' => 'nullable|numeric|min:0',
        ]);

        try {
            $stock = $this->stocks->stockIn(
                $validated['product_id'],
                $validated['warehouse_id'],
                $validated['quantity'],
                $validated['cost_price'] ?? null,
            );

            return response()->json(['data' => $stock, 'message' => '入库成功']);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
