<?php

namespace Modules\Business\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PriceBatchController extends Controller
{
    use ResponseTrait;

    public function index(Request $request)
    {
        $query = DB::table('products as p')
            ->select('p.id', 'p.name', 'p.code', 'p.price_unit', 'p.price_unit_small', 'p.price_small', 'p.cost_price', 'p.is_active');

        if ($request->filled('keyword')) {
            $query->where(function ($q) use ($request) {
                $q->where('p.name', 'like', '%'.$request->keyword.'%')
                    ->orWhere('p.code', 'like', '%'.$request->keyword.'%');
            });
        }
        if (isset($request->is_active)) {
            $query->where('p.is_active', $request->is_active);
        }

        $list = $query->orderBy('p.id')
            ->paginate((int) $request->input('page_size', 20), ['*'], 'page', (int) $request->input('page', 1));

        return $this->paginated($list);
    }

    public function batch(Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|integer',
            'items.*.price_small' => 'nullable|numeric|min:0',
            'items.*.cost_price' => 'nullable|numeric|min:0',
        ]);

        $updated = 0;
        foreach ($data['items'] as $item) {
            $update = [];
            if (array_key_exists('price_small', $item)) {
                $update['price_small'] = $item['price_small'];
            }
            if (array_key_exists('cost_price', $item)) {
                $update['cost_price'] = $item['cost_price'];
            }
            if (! empty($update)) {
                $updated += DB::table('products')->where('id', $item['id'])->update($update);
            }
        }

        return $this->success(['updated' => $updated]);
    }
}
