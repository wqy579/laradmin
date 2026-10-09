<?php

namespace Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Delivery\Models\DeliveryCheck;
use Modules\Delivery\Models\DeliveryPick;
use Modules\Delivery\Services\DeliveryFlowService;
use Modules\Stock\Models\StockAdjust;

/**
 * 验货单管理（验货员验货）。由拣货单自动生成，录入实际验货数量，
 * 差异必须填备注；确认验货后可被装车单引用，验货异常退回拣货环节，
 * 同时按差异生成报损单草稿供库管审核扣减库存。
 */
class CheckController extends Controller
{
    use ResponseTrait;

    public function __construct(private DeliveryFlowService $flow) {}

    public function index(Request $request)
    {
        $query = DeliveryCheck::query()->with('items');

        if ($no = $request->input('check_no')) {
            $query->where('check_no', 'like', "%{$no}%");
        }
        if ($pickNo = $request->input('pick_no')) {
            $query->where('pick_no', 'like', "%{$pickNo}%");
        }
        if ($checker = $request->input('checker_name')) {
            $query->where('checker_name', 'like', "%{$checker}%");
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($range = $request->input('date_range')) {
            [$start, $end] = is_array($range) ? $range : explode(',', (string) $range);
            if ($start ?? null) {
                $query->where('check_date', '>=', $start);
            }
            if ($end ?? null) {
                $query->where('check_date', '<=', $end);
            }
        }

        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(200, max(10, (int) $request->input('page_size', 20)));

        return $this->paginated($query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page));
    }

    public function show(int $id)
    {
        $check = DeliveryCheck::with('items')->find($id);
        if (! $check) {
            return $this->error('验货单不存在', 404);
        }

        return $this->success($check);
    }

    /** 开始验货（标记验货中） */
    public function start(Request $request, int $id)
    {
        $check = DeliveryCheck::find($id);
        if (! $check) {
            return $this->error('验货单不存在', 404);
        }
        if ($check->status !== DeliveryCheck::STATUS_PENDING) {
            return $this->error('当前状态不可开始验货', 422);
        }
        $admin = auth('admin')->user();
        $check->update([
            'status' => DeliveryCheck::STATUS_CHECKING,
            'checker_id' => $admin?->id,
            'checker_name' => $admin?->username,
        ]);

        return $this->success($check, '开始验货');
    }

    /** 确认验货：录入实际验货数量，差异必须填备注 */
    public function confirm(Request $request, int $id)
    {
        $check = DeliveryCheck::with('items')->find($id);
        if (! $check) {
            return $this->error('验货单不存在', 404);
        }
        if (! in_array($check->status, [DeliveryCheck::STATUS_PENDING, DeliveryCheck::STATUS_CHECKING], true)) {
            return $this->error('当前状态不可确认验货', 422);
        }

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|integer',
            'items.*.actual_qty' => 'required|integer|min:0',
            'items.*.remark' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $diffTotal = 0;
            $actualTotal = 0;
            $hasDiff = false;
            foreach ($validated['items'] as $row) {
                $item = $check->items->firstWhere('id', $row['id']);
                if (! $item) {
                    throw new \Exception('验货明细不存在');
                }
                $actual = (int) $row['actual_qty'];
                if ($actual > (int) $item->pick_qty) {
                    throw new \Exception('实验数量不能超过拣货数量');
                }
                $diff = (int) $item->pick_qty - $actual;
                // 有差异必须填备注
                if ($diff !== 0 && empty($row['remark'])) {
                    throw new \Exception('存在数量差异，必须填写备注');
                }
                if ($diff !== 0) {
                    $hasDiff = true;
                }
                $item->update([
                    'actual_qty' => $actual,
                    'diff_qty' => $diff,
                    'remark' => $row['remark'] ?? null,
                ]);
                $diffTotal += $diff;
                $actualTotal += $actual;
            }

            $check->actual_qty = $actualTotal;
            $check->diff_qty = $diffTotal;

            if ($hasDiff) {
                // 验货异常：退回拣货环节
                $check->status = DeliveryCheck::STATUS_EXCEPTION;
                $check->save();
                // 拣货单状态回退为拣货中，重新拣货
                DB::table('delivery_pick')->where('id', $check->pick_id)->update(['status' => 'picking']);

                // 验货差异生成库存调整草稿（报损/报溢），供库管审核后扣减/增加库存
                $warehouseId = (int) DeliveryPick::where('id', $check->pick_id)->value('warehouse_id');
                $admin = auth('admin')->user();
                $this->flow->createStockAdjustFromCheckDiff(
                    $check,
                    $warehouseId,
                    $admin?->id,
                    $admin?->real_name ?? $admin?->username,
                );

                DB::commit();

                return $this->success($check->fresh(['items']), '验货存在差异，已标记异常并退回拣货环节，已生成库存调整草稿待审核');
            }

            $check->status = DeliveryCheck::STATUS_CHECKED;
            $check->save();

            DB::commit();

            return $this->success($check->fresh(['items']), '验货通过，可装车');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        }
    }

    /** 待装车验货单列表（已验货可被装车单引用） */
    public function unchecked(Request $request)
    {
        $list = DeliveryCheck::where('status', DeliveryCheck::STATUS_CHECKED)
            ->whereNotIn('id', function ($q) {
                $q->select('check_id')->from('delivery_load_items')->whereNotNull('check_id');
            })
            ->leftJoin('customers as c', 'c.id', '=', 'delivery_check.customer_id')
            ->orderByDesc('delivery_check.id')
            ->limit(100)
            ->get([
                'delivery_check.id', 'delivery_check.check_no', 'delivery_check.customer_id',
                'c.name as customer_name', 'c.address', 'c.phone',
                'delivery_check.actual_qty as total_qty', 'delivery_check.total_skus',
            ]);

        return $this->success($list);
    }
}
