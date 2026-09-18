<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User as AdminUser;
use Modules\Order\Models\Customer;
use Modules\Order\Models\SalesOrder;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\Warehouse;

class SalesOrderController extends Controller
{
    /**
     * 明细行金额：大单位 + 中单位 + 小单位三段相加。
     *
     * 与旧系统前端 calcAmount 完全一致（09-handlers-order.js）：
     *   amount = qty_large*price_large + qty_medium*price_medium + qty_small*price_small
     * 只在后端算一遍，不信前端传过来的 amount，避免前端绕过或算错。
     */
    private function itemAmount(array $data): float
    {
        // 三档数量都没填时回落到 quantity × price：兼容旧版简单接口契约
        // （只传 quantity/price 的调用方），避免算出一笔 0 元单。
        if (((float) ($data['qty_large'] ?? 0) + (float) ($data['qty_medium'] ?? 0) + (float) ($data['qty_small'] ?? 0)) == 0.0) {
            return round((float) ($data['quantity'] ?? 0) * (float) ($data['price'] ?? 0), 2);
        }

        return round(
            (float) ($data['qty_large'] ?? 0) * (float) ($data['price_large'] ?? 0)
            + (float) ($data['qty_medium'] ?? 0) * (float) ($data['price_medium'] ?? 0)
            + (float) ($data['qty_small'] ?? 0) * (float) ($data['price_small'] ?? 0),
            2
        );
    }

    /**
     * 赠品 / 陈列费 的单价必须为 0。
     *
     * 旧系统在前端 onModeChange 里清掉三档单价；这里再兜一道，防止绕过前端
     * 直接调接口把「赠品」记成一个有价格的商品——那种单据在报表里会算出钱来。
     */
    private function clearPriceForGiftMode(array $data): array
    {
        if (in_array($data['sale_mode'] ?? '', ['赠品', '陈列费'], true)) {
            $data['price_large'] = 0;
            $data['price_medium'] = 0;
            $data['price_small'] = 0;
            $data['price'] = 0;
            $data['price_source'] = '特殊';
        }

        return $data;
    }

    /**
     * 明细行折算成小单位的总数量。
     *
     * 与旧系统前端 submitOrder 完全一致：
     *   unit_conversion_medium(mc) > 0 时：quantity = qty_large*unit_conversion*mc + qty_medium*mc + qty_small
     *   否则（只有大/小两个单位）：        quantity = qty_large*unit_conversion + qty_small
     * 换算系数取自商品本身——前端提交时并不知道换算关系，所以这里回查一次。
     * 找不到商品（异常数据）时回落到前端传来的 quantity，绝不静默算成 0。
     */
    private function itemQuantity(array $data, int $productId): int
    {
        // value() 不认主键，会直接返回第一行的值——这里必须按 id 查。
        // unit_conversion 在库里是 string 列（见 create_business_tables），
        // 直接从 SQLite 读回来是 '480' 这种字符串，转 float 再四舍五入
        // 才能正确折算 1 件 = 4 盒 = 480 个。
        $product = Product::find($productId);
        $conv = (float) ($product->unit_conversion ?? 0);
        $convMd = (float) ($product->unit_conversion_medium ?? 0);
        $large = (int) ($data['qty_large'] ?? 0);
        $medium = (int) ($data['qty_medium'] ?? 0);
        $small = (int) ($data['qty_small'] ?? 0);

        $qty = $convMd > 0
            ? $large * $conv * $convMd + $medium * $convMd + $small
            : $large * $conv + $small;

        $qty = (int) round($qty);

        // 三档数量都没填（旧版简单契约）时直接用 quantity
        return $qty > 0 ? $qty : (int) ($data['quantity'] ?? 0);
    }

    public function index(Request $request)
    {
        $query = SalesOrder::with(['customer', 'warehouse', 'salesman', 'items.product']);
        if ($request->filled('keyword')) {
            $query->where('order_no', 'like', '%'.$request->keyword.'%');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        $query->orderBy('id', 'desc');
        $orders = $query->paginate($request->integer('per_page', 20));
        $customers = Customer::where('is_active', true)->orderBy('name')->get();
        $warehouses = Warehouse::where('is_active', true)->get();
        // 业务员沿用旧系统的业务员下拉：取后台用户（auth_user），新增订单时按人选择
        $salesmen = AdminUser::orderBy('id')->get(['id', 'username', 'real_name']);

        return $this->success([
            'list' => $orders->items(),
            'total' => $orders->total(),
            'page' => $orders->currentPage(),
            'page_size' => $orders->perPage(),
            'last_page' => $orders->lastPage(),
            'customers' => $customers,
            'warehouses' => $warehouses,
            'salesmen' => $salesmen,
        ]);
    }

    public function show(SalesOrder $salesOrder)
    {
        $salesOrder->load(['customer', 'warehouse', 'salesman', 'items.product']);

        return $this->success($salesOrder);
    }

    public function store(Request $request)
    {
        $validated = $this->validateOrder($request);
        $orderNo = 'SO'.date('YmdHis').strtoupper(Str::random(4));
        DB::beginTransaction();
        try {
            // 注意不能用 AdminUser::value($id, 'real_name')——那会忽略 id 直接取第一条。
            $salesmanId = $validated['salesman_id'] ?? null;
            $salesmanName = $salesmanId ? AdminUser::firstWhere('id', $salesmanId)?->real_name : null;

            $order = SalesOrder::create([
                'order_no' => $orderNo,
                'customer_id' => $validated['customer_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'order_date' => $validated['order_date'],
                'salesman_id' => $salesmanId,
                'salesman_name' => $salesmanName,
                'remark' => $validated['remark'] ?? null,
                'status' => 'draft',
                'total_amount' => 0,
                'total_qty' => 0,
                'created_by' => auth('admin')->id(),
            ]);
            $totals = $this->storeItems($order, $validated['items']);
            $order->update($totals);
            DB::commit();

            return $this->created($order->fresh(['customer', 'warehouse', 'salesman', 'items.product']), '订单创建成功');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error('订单创建失败: '.$e->getMessage(), 500);
        }
    }

    public function update(Request $request, SalesOrder $salesOrder)
    {
        if ($salesOrder->status !== 'draft') {
            return $this->error('只有草稿状态可以编辑', 422);
        }
        $validated = $this->validateOrder($request);
        DB::beginTransaction();
        try {
            // 同 store：nullable 字段可能不存在
            $salesmanId = $validated['salesman_id'] ?? null;
            $salesmanName = $salesmanId ? AdminUser::firstWhere('id', $salesmanId)?->real_name : null;

            $salesOrder->update([
                'customer_id' => $validated['customer_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'order_date' => $validated['order_date'],
                'salesman_id' => $salesmanId,
                'salesman_name' => $salesmanName,
                'remark' => $validated['remark'] ?? null,
            ]);
            $salesOrder->items()->delete();
            $totals = $this->storeItems($salesOrder, $validated['items']);
            $salesOrder->update($totals);
            DB::commit();

            return $this->success($salesOrder->fresh(['customer', 'warehouse', 'salesman', 'items.product']), '更新成功');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error('更新失败: '.$e->getMessage(), 500);
        }
    }

    /**
     * 新增/编辑共用的订单校验。
     *
     * 与旧系统提交前的校验对齐：客户、仓库、日期必填；明细至少一行。
     * 三档数量/单价一律可选（旧系统的每一行都可能只填小单位），
     * sale_mode 是中文标签（正常销售/赠品/陈列费/试用/特价销售/返利），
     * price_source 只允许业务规则写入的「特殊」标记，不信任前端传别的值。
     */
    private function validateOrder(Request $request): array
    {
        $rules = [
            'customer_id' => 'required|exists:customers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'order_date' => 'required|date',
            'salesman_id' => 'nullable|exists:auth_user,id',
            'remark' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'nullable|integer|min:0',
            'items.*.qty_large' => 'nullable|integer|min:0',
            'items.*.qty_medium' => 'nullable|integer|min:0',
            'items.*.qty_small' => 'nullable|integer|min:0',
            'items.*.price' => 'nullable|numeric|min:0',
            'items.*.price_large' => 'nullable|numeric|min:0',
            'items.*.price_medium' => 'nullable|numeric|min:0',
            'items.*.price_small' => 'nullable|numeric|min:0',
            'items.*.sale_mode' => 'nullable|string|max:20',
            'items.*.remark' => 'nullable|string|max:500',
            'items.*.price_source' => ['nullable', 'string', 'max:20', 'in:,特殊'],
        ];

        $messages = [
            'customer_id.required' => '请选择客户',
            'warehouse_id.required' => '请选择仓库',
            'order_date.required' => '请选择日期',
            'items.required' => '请至少添加一个商品',
        ];

        return $request->validate($rules, $messages);
    }

    /**
     * 按旧系统的算法逐行落库，并汇总订单总额/总数量。
     *
     * price 保持旧系统的约定：等于 price_small（小单位单价），供旧版报表与库存口径使用；
     * 若调用方走旧版简单契约只传了 price，则回落到该值。
     * 赠品/陈列费这类清零的单价原样入库——清不清晰由 price_source='特殊' 说明，
     * 不在后端二次判断，避免与前端业务规则打架。
     */
    private function storeItems(SalesOrder $order, array $items): array
    {
        $totalAmount = 0;
        $totalQty = 0;

        foreach ($items as $itemData) {
            $itemData = $this->clearPriceForGiftMode($itemData);
            $productId = (int) $itemData['product_id'];
            $qtyLarge = (int) ($itemData['qty_large'] ?? 0);
            $qtyMedium = (int) ($itemData['qty_medium'] ?? 0);
            $qtySmall = (int) ($itemData['qty_small'] ?? 0);
            $priceLarge = round((float) ($itemData['price_large'] ?? 0), 2);
            $priceMedium = round((float) ($itemData['price_medium'] ?? 0), 2);
            $priceSmall = round((float) ($itemData['price_small'] ?? 0), 2);

            // 三档单价按旧系统约定：缺省回落到 price（旧版简单契约只传 quantity/price）
            $price = round((float) ($itemData['price'] ?? $itemData['price_small'] ?? 0), 2);

            $amount = $this->itemAmount([
                'qty_large' => $qtyLarge, 'price_large' => $priceLarge,
                'qty_medium' => $qtyMedium, 'price_medium' => $priceMedium,
                'qty_small' => $qtySmall, 'price_small' => $priceSmall,
                'quantity' => $itemData['quantity'] ?? 0,
                'price' => $price,
            ]);
            $quantity = $this->itemQuantity($itemData, $productId);

            $order->items()->create([
                'product_id' => $productId,
                'quantity' => $quantity,
                'qty_large' => $qtyLarge,
                'qty_medium' => $qtyMedium,
                'qty_small' => $qtySmall,
                'price' => $price,
                'price_large' => $priceLarge,
                'price_medium' => $priceMedium,
                'price_small' => $priceSmall,
                'amount' => $amount,
                'sale_mode' => $itemData['sale_mode'] ?? '正常销售',
                'price_source' => ($itemData['price_source'] ?? '') === '特殊' ? '特殊' : null,
                'remark' => $itemData['remark'] ?? null,
            ]);

            $totalAmount += $amount;
            $totalQty += $quantity;
        }

        return ['total_amount' => round($totalAmount, 2), 'total_qty' => $totalQty];
    }

    public function destroy(SalesOrder $salesOrder)
    {
        if ($salesOrder->status !== 'draft') {
            return response()->json(['message' => '只有草稿状态可以删除'], 422);
        }
        $salesOrder->delete();

        return response()->json(['message' => '删除成功']);
    }

    public function approve(SalesOrder $salesOrder)
    {
        // 与采购单同一套前置校验：只有草稿能审批，避免状态被反复翻转、审计字段被覆盖
        if ($salesOrder->status !== 'draft') {
            return response()->json(['message' => '只有草稿状态的订单可以审批'], 422);
        }
        $salesOrder->update(['status' => 'approved', 'approved_by' => auth('admin')->id(), 'approved_at' => now()]);

        return response()->json(['message' => '审批成功']);
    }

    public function cancel(SalesOrder $salesOrder)
    {
        // 与采购单对称：只有草稿/已审批可取消。已进入发货流程的订单
        // 库存可能已动，不能一键抹回，避免状态与库存不一致。
        if (! in_array($salesOrder->status, ['draft', 'approved'], true)) {
            return response()->json(['message' => '只有草稿或已审批的订单可以取消'], 422);
        }
        $salesOrder->update(['status' => 'cancelled']);

        return response()->json(['message' => '取消成功']);
    }

    public function statistics()
    {
        $stats = [
            'total_orders' => SalesOrder::count(),
            'total_amount' => SalesOrder::sum('total_amount'),
            'pending' => SalesOrder::where('status', 'draft')->count(),
            'approved' => SalesOrder::where('status', 'approved')->count(),
        ];

        return response()->json(['data' => $stats]);
    }
}
