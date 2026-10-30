<?php

namespace Modules\Mall\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Mall\Models\MallOrder;
use Modules\Mall\Models\MallOrderItem;
use Modules\Mall\Services\MallOrderService;
use Modules\Stock\Exceptions\StockRuleException;
use Modules\Stock\Models\Warehouse;

/**
 * F5 分销商城 —— 订单（前台下单 + 后台接单/拒单）。
 *
 * 下单走 MallOrderService → 生成订货申报单（F1 通道）。
 * 后台接单/拒绝只更新商城侧状态与申报单备注，不改写 Order 域数据。
 */
class MallOrderController extends Controller
{
    use ResponseTrait;

    public function __construct(private MallOrderService $service) {}

    /** 后台订单列表（含申报单关联） */
    public function index(Request $request)
    {
        $query = MallOrder::with('items')
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $kw = $request->keyword;
                $q->where(fn ($q) => $q->where('order_no', 'like', '%'.$kw.'%')
                    ->orWhere('customer_name', 'like', '%'.$kw.'%')
                    ->orWhere('sales_order_no', 'like', '%'.$kw.'%'));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('start_date'), fn ($q) => $q->whereDate('order_date', '>=', $request->start_date))
            ->when($request->filled('end_date'), fn ($q) => $q->whereDate('order_date', '<=', $request->end_date));

        $rows = $query->orderByDesc('id')->paginate($request->integer('page_size', 20));

        $items = collect($rows->items())->map(fn (MallOrder $o) => $this->card($o))->all();

        return $this->success([
            'list' => $items,
            'total' => $rows->total(),
            'page' => $rows->currentPage(),
            'page_size' => $rows->perPage(),
            'last_page' => $rows->lastPage(),
        ]);
    }

    /** 前台下单（同时生成申报单） */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer',
            'warehouse_id' => 'nullable|integer',
            'order_date' => 'nullable|date',
            'idempotency_key' => 'nullable|string|max:64',
            'receiver_name' => 'nullable|string|max:50',
            'receiver_phone' => 'nullable|string|max:30',
            'receiver_address' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.qty_large' => 'nullable|integer|min:0',
            'items.*.qty_medium' => 'nullable|integer|min:0',
            'items.*.qty_small' => 'nullable|integer|min:0',
        ]);

        try {
            $mallOrder = $this->service->placeOrder($validated, (int) auth('admin')->id());
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (StockRuleException $e) {
            // 并发下权威库存校验（lockForUpdate freeze）失败：明确报「库存不足」，不落 500。
            return $this->error($e->getMessage(), 422);
        }

        return $this->created($this->card($mallOrder->fresh('items')), '下单成功，已生成申报单');
    }

    /** 详情 */
    public function show(int $id)
    {
        $order = MallOrder::with('items')->findOrFail($id);

        return $this->success($this->card($order));
    }

    /** 接单：确认商城单 + 标记申报单已确认 */
    public function accept(Request $request, int $id)
    {
        $order = MallOrder::findOrFail($id);
        if ($order->status !== 'pending') {
            return $this->error('只有待接单状态可以接单', 422);
        }

        $order->update([
            'status' => 'accepted',
            'accepted_by' => auth('admin')->id(),
            'accepted_at' => now(),
            'accept_remark' => $request->input('remark'),
        ]);

        return $this->success($this->card($order->fresh('items')), '已接单');
    }

    /** 拒绝 */
    public function reject(Request $request, int $id)
    {
        $order = MallOrder::findOrFail($id);
        if ($order->status !== 'pending') {
            return $this->error('只有待接单状态可以拒绝', 422);
        }

        $order->update([
            'status' => 'rejected',
            'accepted_by' => auth('admin')->id(),
            'accepted_at' => now(),
            'accept_remark' => $request->input('remark'),
        ]);

        return $this->success($this->card($order->fresh('items')), '已拒绝');
    }

    /** 取消（下单方） */
    public function cancel(Request $request, int $id)
    {
        $order = MallOrder::findOrFail($id);
        if (! in_array($order->status, ['pending', 'accepted'], true)) {
            return $this->error('当前状态不可取消', 422);
        }

        $order->update([
            'status' => 'cancelled',
            'accept_remark' => $request->input('remark'),
        ]);

        return $this->success($this->card($order->fresh('items')), '已取消');
    }

    /** 可选的提货仓库（前台下单用） */
    public function warehouses()
    {
        $rows = Warehouse::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($w) => ['id' => (int) $w->id, 'name' => $w->name, 'code' => $w->code]);

        return $this->success(['warehouses' => $rows]);
    }

    /** 组装订单卡（含明细 + 申报单号） */
    private function card(MallOrder $o): array
    {
        return [
            'id' => $o->id,
            'order_no' => $o->order_no,
            'customer_id' => $o->customer_id,
            'customer_name' => $o->customer_name,
            'warehouse_id' => $o->warehouse_id,
            'order_date' => $o->order_date?->toDateString(),
            'total_amount' => (float) $o->total_amount,
            'total_qty' => (int) $o->total_qty,
            'paid_amount' => (float) $o->paid_amount,
            'status' => $o->status,
            'status_label' => MallOrder::statusLabel($o->status),
            'status_tag_type' => MallOrder::statusTagType($o->status),
            'receiver_name' => $o->receiver_name,
            'receiver_phone' => $o->receiver_phone,
            'receiver_address' => $o->receiver_address,
            'sales_order_id' => $o->sales_order_id,
            'sales_order_no' => $o->sales_order_no,
            'creator_name' => $o->creator_name,
            'accepted_at' => $o->accepted_at?->toDateTimeString(),
            'accept_remark' => $o->accept_remark,
            'created_at' => $o->created_at?->toDateTimeString(),
            'items' => $o->items->map(fn (MallOrderItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_code' => $i->product_code,
                'product_name' => $i->product_name,
                'spec' => $i->spec,
                'unit_large' => $i->unit_large,
                'unit_medium' => $i->unit_medium,
                'unit_small' => $i->unit_small,
                'unit_conversion' => (int) $i->unit_conversion,
                'unit_conversion_medium' => (int) $i->unit_conversion_medium,
                'qty_large' => (int) $i->qty_large,
                'qty_medium' => (int) $i->qty_medium,
                'qty_small' => (int) $i->qty_small,
                'price_large' => (float) $i->price_large,
                'price_medium' => (float) $i->price_medium,
                'price_small' => (float) $i->price_small,
                'quantity' => (int) $i->quantity,
                'amount' => (float) $i->amount,
            ])->all(),
        ];
    }
}
