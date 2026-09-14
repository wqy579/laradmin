<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Business\Models\Stock;
use Modules\Business\Services\StockService;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function __construct(private StockService $stocks)
    {
    }

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
}
