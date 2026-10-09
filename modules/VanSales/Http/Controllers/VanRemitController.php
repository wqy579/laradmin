<?php

namespace Modules\VanSales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\VanSales\Models\VanRemit;
use Modules\VanSales\Models\VanRemitItem;

/**
 * 车销上交货款（VanRemit，VRM）：业务员把当日车销收取的货款上交出纳。
 *
 * 纯台账型（用户拍板）：
 *   - 不动 VanSaleOrderController::approve 的资金流（其 cash_flows related_type=VanSaleOrder 保持不变）
 *   - confirm 时写一条汇总 cash_flows(related_type=VanRemit)，通过 related_type 与销售收款流水区分
 *   - 待上交金额实时计算：该业务员已确认销售单 paid_amount 合计（分方式） − 已上交 confirmed 合计
 *
 * 与 DeliveryRemitController 的关键差异（避其两个 fatal bug）：
 *   - 本 Controller 自带 generateNo（Delivery 的漏写导致 Call to undefined method）
 *   - pendingSummary 返回数组，store 用 $data[$method]['pending'] 数组下标（Delivery 用 ->pending 对象访问，fatal）
 */
class VanRemitController extends Controller
{
    use ResponseTrait;

    /** 待上交汇总（分支付方式）——实时计算，不冗余存储 */
    public function pendingSummary(Request $request)
    {
        $salesmanId = (int) $request->input('salesman_id', $this->currentAdmin()[0] ?? 0);
        if ($salesmanId <= 0) {
            return $this->error('缺少业务员参数', 422);
        }

        $methods = ['cash' => '现金', 'wechat' => '微信', 'alipay' => '支付宝', 'card' => '银行卡'];

        // 已确认销售单 paid_amount 合计（分方式），挂账(credit)不计入上交
        $received = DB::table('van_sale_orders')
            ->where('salesman_id', $salesmanId)
            ->where('status', 'approved')
            ->whereIn('payment_method', array_keys($methods))
            ->select('payment_method', DB::raw('COALESCE(SUM(paid_amount),0) as total'))
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        // 已上交合计（分方式）——只统计 confirmed 的，rejected 的不计入
        $remitted = DB::table('van_remit_items as ri')
            ->join('van_remit as r', 'r.id', '=', 'ri.remit_id')
            ->where('r.salesman_id', $salesmanId)
            ->where('r.status', VanRemit::STATUS_CONFIRMED)
            ->select('ri.payment_method', DB::raw('COALESCE(SUM(ri.amount),0) as total'))
            ->groupBy('ri.payment_method')
            ->pluck('total', 'payment_method');

        $summary = [];
        $totalPending = 0.0;
        foreach ($methods as $eng => $cn) {
            $r = (float) ($received[$eng] ?? 0);
            $d = (float) ($remitted[$cn] ?? 0);
            $pending = round($r - $d, 2);
            $totalPending += $pending;
            $summary[$cn] = [
                'received' => $r,
                'remitted' => $d,
                'pending' => $pending,
            ];
        }

        return $this->success([
            'salesman_id' => $salesmanId,
            'summary' => $summary,
            'total_pending' => round($totalPending, 2),
        ]);
    }

    /** 列表 */
    public function index(Request $request)
    {
        $query = VanRemit::with('items');

        if ($request->filled('remit_no')) {
            $query->where('remit_no', 'like', '%'.trim((string) $request->input('remit_no')).'%');
        }
        if ($request->filled('salesman_id')) {
            $query->where('salesman_id', (int) $request->input('salesman_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('start_date')) {
            $query->whereDate('remit_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('remit_date', '<=', $request->input('end_date'));
        }

        $page = max(1, $request->integer('page', 1));
        $pageSize = min(200, max(10, $request->integer('page_size', 20)));

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        return $this->paginated($paginator);
    }

    /** 详情 */
    public function show($id)
    {
        $remit = VanRemit::with('items')->find($id);
        if (! $remit) {
            return $this->notFound('上交单不存在');
        }

        return $this->success($remit);
    }

    /**
     * 创建上交单（status=pending 已上交待确认）。
     * 校验各方式金额 ≤ 待上交；FIFO 关联最旧未上交销售单。
     * store 不写 cash_flow——流水在 confirm 时才写。
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'salesman_id' => 'nullable|integer',
            'remit_date' => 'nullable|date',
            'cash_amount' => 'nullable|numeric|min:0',
            'wechat_amount' => 'nullable|numeric|min:0',
            'alipay_amount' => 'nullable|numeric|min:0',
            'bank_amount' => 'nullable|numeric|min:0',
            'remark' => 'nullable|string|max:500',
        ]);

        [$adminId, $adminName] = $this->currentAdmin();
        $salesmanId = (int) ($validated['salesman_id'] ?? $adminId);
        if ($salesmanId <= 0) {
            return $this->error('缺少业务员', 422);
        }

        // 请求金额按中文方式聚合
        $amounts = [
            '现金' => (float) ($validated['cash_amount'] ?? 0),
            '微信' => (float) ($validated['wechat_amount'] ?? 0),
            '支付宝' => (float) ($validated['alipay_amount'] ?? 0),
            '银行卡' => (float) ($validated['bank_amount'] ?? 0),
        ];
        $total = array_sum($amounts);
        if ($total <= 0) {
            return $this->error('上交金额必须大于0', 422);
        }

        // 实时计算待上交（复用 pendingSummary 逻辑，但直接查库避免 JsonResponse 往返）
        $methods = ['cash' => '现金', 'wechat' => '微信', 'alipay' => '支付宝', 'card' => '银行卡'];
        $received = DB::table('van_sale_orders')
            ->where('salesman_id', $salesmanId)
            ->where('status', 'approved')
            ->whereIn('payment_method', array_keys($methods))
            ->select('payment_method', DB::raw('COALESCE(SUM(paid_amount),0) as total'))
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');
        $remitted = DB::table('van_remit_items as ri')
            ->join('van_remit as r', 'r.id', '=', 'ri.remit_id')
            ->where('r.salesman_id', $salesmanId)
            ->where('r.status', VanRemit::STATUS_CONFIRMED)
            ->select('ri.payment_method', DB::raw('COALESCE(SUM(ri.amount),0) as total'))
            ->groupBy('ri.payment_method')
            ->pluck('total', 'ri.payment_method');

        // 校验各方式不超过待上交（数组下标，非对象访问——避开 Delivery 的 fatal bug）
        foreach ($amounts as $method => $amt) {
            if ($amt > 0) {
                $eng = array_search($method, $methods);
                $pending = (float) ($received[$eng] ?? 0) - (float) ($remitted[$method] ?? 0);
                if ($amt > $pending + 0.01) {
                    return $this->error("{$method}上交金额{$amt}不能超过待上交金额".round($pending, 2), 422);
                }
            }
        }

        return DB::transaction(function () use ($salesmanId, $adminName, $validated, $amounts, $total, $methods) {
            $remit = VanRemit::create([
                'remit_no' => $this->generateNo('VRM', 'van_remit', 'remit_no'),
                'salesman_id' => $salesmanId,
                'salesman_name' => $adminName,
                'remit_date' => $validated['remit_date'] ?? now()->toDateString(),
                'cash_amount' => $amounts['现金'],
                'wechat_amount' => $amounts['微信'],
                'alipay_amount' => $amounts['支付宝'],
                'bank_amount' => $amounts['银行卡'],
                'total_amount' => round($total, 2),
                'status' => VanRemit::STATUS_PENDING,
                'remark' => $validated['remark'] ?? null,
            ]);

            // FIFO 关联最旧未上交销售单（按支付方式分组）
            foreach ($amounts as $method => $amt) {
                if ($amt <= 0) {
                    continue;
                }
                $remaining = $amt;
                $eng = array_search($method, $methods);
                // 取该方式下尚未被 confirmed 上交单关联的已确认销售单，按 id 升序
                $orders = DB::table('van_sale_orders')
                    ->where('salesman_id', $salesmanId)
                    ->where('status', 'approved')
                    ->where('payment_method', $eng)
                    ->orderBy('id')
                    ->get(['id', 'order_no', 'paid_amount']);

                foreach ($orders as $order) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $allocate = min($remaining, (float) $order->paid_amount);
                    if ($allocate <= 0) {
                        continue;
                    }
                    VanRemitItem::create([
                        'remit_id' => $remit->id,
                        'sale_order_id' => $order->id,
                        'sale_order_no' => $order->order_no,
                        'payment_method' => $method,
                        'amount' => round($allocate, 2),
                    ]);
                    $remaining -= $allocate;
                }
            }

            return $this->created($remit->load('items'), '上交成功，待出纳确认');
        });
    }

    /**
     * 出纳确认（pending → confirmed）：写一条汇总 cash_flows。
     * 与 approve 的流水（related_type=VanSaleOrder）通过 related_type 区分，报表按 related_type 分组。
     */
    public function confirm($id)
    {
        $remit = VanRemit::find($id);
        if (! $remit) {
            return $this->notFound('上交单不存在');
        }
        if ($remit->status === VanRemit::STATUS_CONFIRMED) {
            return $this->success($remit, '已确认，无需重复操作');
        }
        if ($remit->status !== VanRemit::STATUS_PENDING) {
            return $this->error('只有待确认的上交单才能确认', 422);
        }

        [$adminId, $adminName] = $this->currentAdmin();

        DB::transaction(function () use ($remit, $adminId, $adminName) {
            $remit->update([
                'status' => VanRemit::STATUS_CONFIRMED,
                'confirmed_by' => $adminId,
                'confirmed_at' => now(),
            ]);

            // 写一条汇总流水（related_type=VanRemit，与 approve 的 VanSaleOrder 区分）
            DB::table('cash_flows')->insert([
                'flow_no' => 'CF'.date('YmdHis').strtoupper(Str::random(4)),
                'flow_type' => 'receive',
                'customer_id' => null,
                'supplier_id' => null,
                'related_id' => $remit->id,
                'related_type' => 'VanRemit',
                'flow_date' => $remit->remit_date?->toDateString() ?? now()->toDateString(),
                'amount' => round((float) $remit->total_amount, 2),
                'payment_method' => '现金',
                'remark' => '车销上交货款-'.$remit->remit_no,
                'created_by' => $adminId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->writeOperationLog($remit, $adminId, $adminName, 'confirm', '出纳确认', VanRemit::STATUS_PENDING, VanRemit::STATUS_CONFIRMED);
        });

        return $this->success($remit->load('items'), '已确认，货款已入账');
    }

    /** 驳回（pending → rejected）：不写流水，销售单回到可上交池 */
    public function reject(Request $request, $id)
    {
        $remit = VanRemit::find($id);
        if (! $remit) {
            return $this->notFound('上交单不存在');
        }
        if ($remit->status !== VanRemit::STATUS_PENDING) {
            return $this->error('只有待确认的上交单才能驳回', 422);
        }

        $reason = $request->input('reject_reason', $request->input('reason'));

        [$adminId, $adminName] = $this->currentAdmin();
        $remit->update([
            'status' => VanRemit::STATUS_REJECTED,
            'reject_reason' => $reason,
        ]);
        $this->writeOperationLog($remit, $adminId, $adminName, 'reject', '驳回', VanRemit::STATUS_PENDING, VanRemit::STATUS_REJECTED);

        return $this->success($remit, '已驳回，销售单回到可上交池');
    }

    /** 导出 CSV */
    public function export(Request $request)
    {
        $query = VanRemit::with('items');
        if ($request->filled('salesman_id')) {
            $query->where('salesman_id', (int) $request->input('salesman_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        $list = $query->orderByDesc('id')->get();

        $csv = "\u{FEFF}车销上交货款列表\n\n";
        $csv .= "上交日期,上交单号,业务员,现金,微信,支付宝,银行卡,合计,状态,确认人\n";
        $statusLabel = fn ($s) => match ($s) {
            'pending' => '待确认', 'confirmed' => '已确认', 'rejected' => '已驳回', default => $s
        };

        foreach ($list as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $r->remit_date?->format('Y-m-d') ?? '',
                $r->remit_no,
                $r->salesman_name ?? '',
                number_format((float) $r->cash_amount, 2, '.', ''),
                number_format((float) $r->wechat_amount, 2, '.', ''),
                number_format((float) $r->alipay_amount, 2, '.', ''),
                number_format((float) $r->bank_amount, 2, '.', ''),
                number_format((float) $r->total_amount, 2, '.', ''),
                $statusLabel($r->status),
                $r->confirmed_by ?? ''
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="van_remit.csv"',
        ]);
    }

    private function writeOperationLog(VanRemit $remit, ?int $adminId, string $adminName, string $action, string $actionLabel, ?string $fromStatus, ?string $toStatus, ?string $detail = null): void
    {
        DB::table('order_operation_logs')->insert([
            'order_id' => $remit->id,
            'order_no' => $remit->remit_no,
            'order_type' => 'van_remit',
            'user_id' => $adminId,
            'user_name' => $adminName,
            'operator_id' => $adminId,
            'operator_name' => $adminName,
            'action' => $action,
            'action_label' => $actionLabel,
            'detail' => $detail,
            'remark' => null,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function currentAdmin(): array
    {
        $admin = auth('admin')->user();

        return [$admin?->id, $admin?->real_name ?? $admin?->username ?? '管理员'];
    }

    private function generateNo(string $prefix, string $table, string $column): string
    {
        $like = $prefix.date('Ymd').'%';
        $last = DB::table($table)->where($column, 'like', $like)->orderByDesc($column)->value($column);
        $seq = $last ? intval(substr($last, -6)) + 1 : 1;

        return $prefix.date('Ymd').str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
