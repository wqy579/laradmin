<?php

namespace App\Http\Controllers\Admin\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\Stock;
use App\Models\Business\Product;
use App\Models\Business\Warehouse;
use Illuminate\Http\Request;

class StockController extends Controller
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
        
        if ($request->filled('keyword')) {
            $query->whereHas('product', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->keyword . '%')
                  ->orWhere('code', 'like', '%' . $request->keyword . '%');
            });
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
    
    public function show(Stock $stock)
    {
        $stock->load(['product', 'warehouse']);
        return response()->json(['data' => $stock]);
    }
    
    public function statistics()
    {
        $stats = [
            'total_products' => Stock::count(),
            'total_quantity' => Stock::sum('quantity'),
            'total_amount' => Stock::sum('total_amount'),
            'low_stock_count' => Stock::whereColumn('quantity', '<', 10)->count(),
        ];
        
        $topProducts = Stock::with('product')
            ->selectRaw('product_id, SUM(quantity) as total_qty')
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->get();
            
        return response()->json([
            'stats' => $stats,
            'top_products' => $topProducts,
        ]);
    }
}
