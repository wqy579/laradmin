<?php

namespace Modules\VanSales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 车上库存管理（VanStock）
 *
 * 不建独立库存表，复用 stocks + stocks_history，按 type='vehicle' 的 warehouse_id 查。
 * 车销装车(stockIn 车上仓)/销售(stockOut 车上仓)/退货(stockIn)等操作已由各自 Controller
 * 通过 StockService 写入 stocks + stocks_history，本控制器只负责查询与预警。
 */
class VanStockController extends Controller
{
    use ResponseTrait;

    /** 车上库存列表（按车辆仓 + 商品） */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'vehicle_warehouse_id' => 'nullable|integer',
            'keyword' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|min:1|max:200',
        ]);

        $query = DB::table('stocks as s')
            ->join('products as p', 's.product_id', '=', 'p.id')
            ->join('warehouses as w', 's.warehouse_id', '=', 'w.id')
            ->where('w.type', 'vehicle')
            ->where('s.quantity', '>', 0);

        if (! empty($validated['vehicle_warehouse_id'])) {
            $query->where('s.warehouse_id', (int) $validated['vehicle_warehouse_id']);
        }
        if (! empty($validated['keyword'])) {
            $kw = $validated['keyword'];
            $query->where(function ($q) use ($kw) {
                $q->where('p.name', 'like', '%'.$kw.'%')
                    ->orWhere('p.code', 'like', '%'.$kw.'%')
                    ->orWhere('p.spec', 'like', '%'.$kw.'%');
            });
        }

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        $total = (clone $query)->count();
        $list = $query->orderByDesc('s.id')
            ->offset(($page - 1) * $pageSize)->limit($pageSize)
            ->get([
                's.id', 's.product_id', 's.warehouse_id',
                'p.code as product_code', 'p.name as product_name', 'p.spec',
                'p.price_unit_small as unit', 'p.main_category_id',
                's.quantity as stock_qty', 's.cost_price', 's.frozen_qty',
                'w.name as warehouse_name', 'w.vehicle_id',
            ])
            ->map(function ($r) {
                $r->stock_qty = (int) $r->stock_qty;
                $r->available_qty = (int) $r->stock_qty - (int) $r->frozen_qty;
                $r->cost_price = (float) $r->cost_price;
                $r->stock_amount = round((int) $r->stock_qty * (float) $r->cost_price, 2);

                return $r;
            });

        return $this->success([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'last_page' => (int) ceil($total / max($pageSize, 1)),
        ]);
    }

    /** 车上库存变动记录（stocks_history 按 vehicle 仓库） */
    public function history(Request $request)
    {
        $validated = $request->validate([
            'vehicle_warehouse_id' => 'nullable|integer',
            'product_id' => 'nullable|integer',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|min:1|max:200',
        ]);

        $vehicleWarehouseIds = DB::table('warehouses')->where('type', 'vehicle')->pluck('id');

        $query = DB::table('stocks_history as sh')
            ->join('products as p', 'sh.product_id', '=', 'p.id')
            ->join('warehouses as w', 'sh.warehouse_id', '=', 'w.id')
            ->whereIn('sh.warehouse_id', $vehicleWarehouseIds);

        if (! empty($validated['vehicle_warehouse_id'])) {
            $query->where('sh.warehouse_id', (int) $validated['vehicle_warehouse_id']);
        }
        if (! empty($validated['product_id'])) {
            $query->where('sh.product_id', (int) $validated['product_id']);
        }
        if (! empty($validated['start_date'])) {
            $query->whereDate('sh.created_at', '>=', $validated['start_date']);
        }
        if (! empty($validated['end_date'])) {
            $query->whereDate('sh.created_at', '<=', $validated['end_date']);
        }

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        $total = (clone $query)->count();
        $list = $query->orderByDesc('sh.id')
            ->offset(($page - 1) * $pageSize)->limit($pageSize)
            ->get([
                'sh.id', 'sh.product_id', 'sh.warehouse_id',
                'p.name as product_name', 'p.spec', 'p.price_unit_small as unit',
                'sh.change_type', 'sh.change_qty', 'sh.before_qty', 'sh.after_qty',
                'sh.related_id', 'sh.related_type', 'sh.remark',
                'w.name as warehouse_name',
                'sh.created_at',
            ]);

        return $this->success([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'last_page' => (int) ceil($total / max($pageSize, 1)),
        ]);
    }

    /** 安全库存预警（车上库存低于阈值的商品） */
    public function warning(Request $request)
    {
        $validated = $request->validate([
            'vehicle_warehouse_id' => 'nullable|integer',
            'threshold' => 'nullable|integer|min:1',
        ]);

        $threshold = (int) ($validated['threshold'] ?? 10);

        $query = DB::table('stocks as s')
            ->join('products as p', 's.product_id', '=', 'p.id')
            ->join('warehouses as w', 's.warehouse_id', '=', 'w.id')
            ->where('w.type', 'vehicle')
            ->where('s.quantity', '<=', $threshold)
            ->where('s.quantity', '>', 0);

        if (! empty($validated['vehicle_warehouse_id'])) {
            $query->where('s.warehouse_id', (int) $validated['vehicle_warehouse_id']);
        }

        $list = $query->orderBy('s.quantity')
            ->get([
                's.id', 's.product_id', 's.warehouse_id',
                'p.code as product_code', 'p.name as product_name', 'p.spec',
                'p.price_unit_small as unit',
                's.quantity as stock_qty', 's.cost_price',
                'w.name as warehouse_name', 'w.vehicle_id',
            ])
            ->map(function ($r) {
                $r->stock_qty = (int) $r->stock_qty;
                $r->cost_price = (float) $r->cost_price;

                return $r;
            });

        return $this->success(['list' => $list, 'total' => $list->count(), 'threshold' => $threshold]);
    }
}
