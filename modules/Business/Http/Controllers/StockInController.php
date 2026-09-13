<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Business\Models\Stock;
use Modules\Business\Models\Product;
use Modules\Business\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockInController extends Controller
{
    public function index(Request $request)
    {
        $query = Stock::with(['product', 'warehouse']);
        
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }
        
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }
        
        $query->orderBy('id', 'desc');
        $stocks = $query->paginate($request->integer('per_page', 20));
        
        $products = Product::where('is_active', true)->orderBy('name')->get();
        $warehouses = Warehouse::where('is_active', true)->get();
        
        return response()->json([
            'data' => $stocks,
            'products' => $products,
            'warehouses' => $warehouses,
        ]);
    }
    
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'quantity' => 'required|integer|min:1',
            'cost_price' => 'nullable|numeric|min:0',
            'remark' => 'nullable|string',
        ]);
        
        DB::beginTransaction();
        try {
            $stock = Stock::updateOrCreate(
                [
                    'product_id' => $validated['product_id'],
                    'warehouse_id' => $validated['warehouse_id'],
                ],
                [
                    'quantity' => DB::raw('quantity + ' . $validated['quantity']),
                    'cost_price' => $validated['cost_price'] ?? 0,
                ]
            );
            
            $stock->touch();
            
            DB::commit();
            
            return response()->json(['data' => $stock, 'message' => '入库成功']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => '入库失败: ' . $e->getMessage()], 500);
        }
    }
}
