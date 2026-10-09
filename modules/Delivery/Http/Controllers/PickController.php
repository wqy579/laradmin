<?php

namespace Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Delivery\Models\DeliveryPick;
use Modules\Delivery\Services\DeliveryFlowService;

/**
 * 拣货单管理（库管拣货）。由配货单自动生成，录入实际拣货数量，确认后生成验货单。
 */
class PickController extends Controller
{
    use ResponseTrait;

    public function __construct(private DeliveryFlowService $flow) {}

    public function index(Request $request)
    {
        $query = DeliveryPick::query()->with('items');

        if ($no = $request->input('pick_no')) {
            $query->where('pick_no', 'like', "%{$no}%");
        }
        if ($pickingNo = $request->input('picking_no')) {
            $query->where('picking_no', 'like', "%{$pickingNo}%");
        }
        if ($warehouseId = $request->input('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }
        if ($picker = $request->input('picker_name')) {
            $query->where('picker_name', 'like', "%{$picker}%");
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($range = $request->input('date_range')) {
            [$start, $end] = is_array($range) ? $range : explode(',', (string) $range);
            if ($start ?? null) {
                $query->where('pick_date', '>=', $start);
            }
            if ($end ?? null) {
                $query->where('pick_date', '<=', $end);
            }
        }

        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(200, max(10, (int) $request->input('page_size', 20)));

        return $this->paginated($query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page));
    }

    public function show(int $id)
    {
        $pick = DeliveryPick::with('items')->find($id);
        if (! $pick) {
            return $this->error('拣货单不存在', 404);
        }

        return $this->success($pick);
    }

    /** 开始拣货（标记拣货中） */
    public function start(Request $request, int $id)
    {
        $pick = DeliveryPick::find($id);
        if (! $pick) {
            return $this->error('拣货单不存在', 404);
        }
        if ($pick->status !== DeliveryPick::STATUS_PENDING) {
            return $this->error('当前状态不可开始拣货', 422);
        }
        $validated = $request->validate(['picker_id' => 'nullable|integer']);
        $admin = auth('admin')->user();
        $pickerId = $validated['picker_id'] ?? $admin?->id;
        $pickerName = $admin?->username;
        if ($pickerId && $pickerId !== $admin?->id) {
            $u = DB::table('auth_user')->where('id', $pickerId)->first();
            $pickerName = $u?->username;
        }
        $pick->update([
            'status' => DeliveryPick::STATUS_PICKING,
            'picker_id' => $pickerId,
            'picker_name' => $pickerName,
        ]);

        return $this->success($pick, '开始拣货');
    }

    /** 确认拣货：录入实际拣货数量（差异生成缺货记录），生成验货单 */
    public function confirm(Request $request, int $id)
    {
        $pick = DeliveryPick::with('items')->find($id);
        if (! $pick) {
            return $this->error('拣货单不存在', 404);
        }
        if (! in_array($pick->status, [DeliveryPick::STATUS_PENDING, DeliveryPick::STATUS_PICKING], true)) {
            return $this->error('当前状态不可确认拣货', 422);
        }

        $validated = $request->validate([
            'picker_id' => 'nullable|integer',
            'items' => 'required|array',
            'items.*.id' => 'required|integer',
            'items.*.actual_qty' => 'required|integer|min:0',
            'items.*.bin_location' => 'nullable|string|max:50',
            'items.*.remark' => 'nullable|string',
        ]);

        $admin = auth('admin')->user();
        $pickerId = $validated['picker_id'] ?? $admin?->id;

        DB::beginTransaction();
        try {
            $shortTotal = 0;
            foreach ($validated['items'] as $row) {
                $item = $pick->items->firstWhere('id', $row['id']);
                if (! $item) {
                    throw new \Exception('拣货明细不存在');
                }
                $actual = (int) $row['actual_qty'];
                if ($actual > (int) $item->pick_qty) {
                    throw new \Exception('实拣数量不能超过应拣数量');
                }
                $short = (int) $item->pick_qty - $actual;
                $item->update([
                    'actual_qty' => $actual,
                    'short_qty' => $short,
                    'bin_location' => $row['bin_location'] ?? null,
                    'remark' => $row['remark'] ?? null,
                ]);
                $shortTotal += $short;
            }

            $pick->short_qty = $shortTotal;
            $pick->status = DeliveryPick::STATUS_PICKED;
            if ($pickerId && ! $pick->picker_id) {
                $pick->picker_id = $pickerId;
                $pick->picker_name = $admin?->username;
            }
            $pick->save();

            // 自动生成验货单
            $check = $this->flow->createCheckFromPick($pick);
            $pick->check_id = $check->id;
            $pick->save();

            DB::commit();

            return $this->success($pick->fresh(['items']), '拣货确认成功，已生成验货单');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        }
    }

    /** 取消拣货单（仅待拣货/拣货中，且验货单未开始） */
    public function cancel(Request $request, int $id)
    {
        $pick = DeliveryPick::find($id);
        if (! $pick) {
            return $this->error('拣货单不存在', 404);
        }
        if (! in_array($pick->status, [DeliveryPick::STATUS_PENDING, DeliveryPick::STATUS_PICKING], true)) {
            return $this->error('当前状态不可取消', 422);
        }
        if ($pick->check_id) {
            $checkStarted = DB::table('delivery_check')
                ->where('pick_id', $pick->id)
                ->whereIn('status', ['checking', 'checked'])
                ->exists();
            if ($checkStarted) {
                return $this->error('验货单已开始验货，不可取消拣货单', 422);
            }
        }

        DB::beginTransaction();
        try {
            $pick->status = DeliveryPick::STATUS_CANCELLED;
            $pick->save();
            if ($pick->check_id) {
                DB::table('delivery_check')->where('id', $pick->check_id)->update(['status' => 'exception']);
            }
            DB::commit();

            return $this->success($pick, '拣货单已取消');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        }
    }
}
