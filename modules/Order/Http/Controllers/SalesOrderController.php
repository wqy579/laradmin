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
use Modules\Stock\Models\Stock;
use Modules\Stock\Models\Warehouse;
use Modules\Stock\Services\StockService;
use Modules\Order\Services\PromotionService;

class SalesOrderController extends Controller
{
    /** 订单冻结/解冻统一走 StockService，保持 stocks 表写入收敛；PromotionService 自动匹配促销 */
    public function __construct(private StockService $stocks, private PromotionService $promotions) {}

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
        // 列表只 load 必要关联（不 load items.product——大对象拖慢，赠品/变价标记单独查）
        $query = SalesOrder::with(['customer:id,name,address,category,route_id', 'warehouse:id,name', 'salesman:id']);
        $this->applyOrderFilters($query, $request);
        $query->orderBy('id', 'desc');
        $orders = $query->paginate($request->integer('per_page', 20));

        // 批量查当前页订单的赠品/变价标记（一次查询代替 N+1）
        $orderIds = $orders->getCollection()->pluck('id')->all();
        $giftFlags = \DB::table('sales_order_items')
            ->whereIn('sales_order_id', $orderIds)
            ->selectRaw('sales_order_id, MAX(CASE WHEN sale_mode IN ("赠品","陈列费") THEN 1 ELSE 0 END) as has_gift, MAX(CASE WHEN price_source = "特殊" AND sale_mode NOT IN ("赠品","陈列费") THEN 1 ELSE 0 END) as has_special')
            ->groupBy('sales_order_id')
            ->get()->keyBy('sales_order_id');

        $adminNames = \DB::table('auth_user')->pluck('username', 'id');
        $list = $orders->getCollection()->map(function ($o) use ($adminNames, $giftFlags) {
            $o->customer_name = $o->customer?->name;
            $o->operator_name = $adminNames[$o->created_by] ?? null;
            $o->warehouse_name = $o->warehouse?->name;
            $o->is_return = false;
            $flags = $giftFlags->get($o->id);
            $o->has_gift = (bool) ($flags?->has_gift);
            $o->has_special = (bool) ($flags?->has_special);

            return $o;
        });

        // status_counts 合并成一次 groupBy 查询（all = 各状态之和）
        $baseQuery = SalesOrder::query();
        $this->applyOrderFilters($baseQuery, $request);
        $statusCounts = (clone $baseQuery)->select('status', \DB::raw('count(*) as cnt'))->groupBy('status')->pluck('cnt', 'status');
        $allCount = $statusCounts->sum();
        $counts = [
            'pending' => $statusCounts['pending'] ?? 0,
            '配货中' => $statusCounts['配货中'] ?? 0,
            '待调度' => $statusCounts['待调度'] ?? 0,
            '待配送' => $statusCounts['待配送'] ?? 0,
            '配送中' => $statusCounts['配送中'] ?? 0,
            '已收款' => $statusCounts['已收款'] ?? 0,
            '待收款' => $statusCounts['待收款'] ?? 0,
            'all' => $allCount,
        ];

        // customers/warehouses/salesmen 只首次加载（后续翻页不传，减少数据量）
        $extra = [];
        if (! $request->has('page') || $request->integer('page', 1) === 1) {
            $extra['customers'] = Customer::where('is_active', true)->orderBy('name')->get(['id', 'name']);
            $extra['warehouses'] = Warehouse::where('is_active', true)->get(['id', 'name']);
            $extra['salesmen'] = \DB::table('employees')->orderBy('id')->get(['id', 'name']);
        }

        return $this->success(array_merge([
            'list' => $list,
            'total' => $orders->total(),
            'page' => $orders->currentPage(),
            'page_size' => $orders->perPage(),
            'last_page' => $orders->lastPage(),
            'status_counts' => $counts,
        ], $extra));
    }

    /**
     * 订单列表/汇总共用的搜索条件：keyword(订单号) / status / customer_id
     * + 左侧汇总联动筛选 salesman_id / vehicle_id / route_id(按客户线路)
     */
    private function applyOrderFilters($query, Request $request): void
    {
        if ($request->filled('keyword')) {
            $query->where('order_no', 'like', '%'.$request->keyword.'%');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->filled('salesman_id')) {
            $query->where('salesman_id', $request->salesman_id);
        }
        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }
        if ($request->filled('route_id')) {
            $query->whereHas('customer', fn ($q) => $q->where('route_id', $request->route_id));
        }
        // 顶部状态 tab：1待配货 2配货中 6待调度 3待配送 7配送中 4已发货收款 5全部
        $statusTab = $request->input('status_tab');
        $tabStatusMap = ['1' => 'pending', '2' => '配货中', '6' => '待调度', '3' => '待配送', '7' => '配送中'];
        if (isset($tabStatusMap[$statusTab])) {
            $query->where('status', $tabStatusMap[$statusTab]);
        } elseif ($statusTab === '4') {
            $query->whereIn('status', ['已收款', '待收款']);
        }
        // 快捷筛选：-1全部 0未打印 1变价 2含赠品 3含备注
        $quickFilter = (int) $request->input('quick_filter', -1);
        if ($quickFilter === 0) {
            $query->where('print_count', 0);
        } elseif ($quickFilter === 1) {
            $query->whereHas('items', fn ($q) => $q->where('price_source', '特殊')->whereNotIn('sale_mode', ['赠品', '陈列费']));
        } elseif ($quickFilter === 2) {
            $query->whereHas('items', fn ($q) => $q->whereIn('sale_mode', ['赠品', '陈列费']));
        } elseif ($quickFilter === 3) {
            $query->whereNotNull('remark')->where('remark', '!=', '');
        }
        // 日期筛选：quick_date（today/yesterday/week）或 start_date/end_date
        $quickDate = $request->input('quick_date');
        if ($quickDate === 'today') {
            $query->whereDate('order_date', now()->toDateString());
        } elseif ($quickDate === 'yesterday') {
            $query->whereDate('order_date', now()->subDay()->toDateString());
        } elseif ($quickDate === 'week') {
            $query->whereDate('order_date', '>=', now()->subDays(6)->toDateString());
        }
        if ($request->filled('start_date')) {
            $query->whereDate('order_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('order_date', '<=', $request->end_date);
        }
    }

    /**
     * 左侧汇总面板：按 业务员 / 车辆 / 客户线路 三维度分组统计订单数、金额、数量。
     * 复用 applyOrderFilters，跟随右侧搜索条件；null 分组归「未分配」。
     */
    public function summary(Request $request)
    {
        $totals = $this->baseSummaryQuery($request)
            ->leftJoin('sales_order_items as soi', 'sales_orders.id', '=', 'soi.sales_order_id')
            ->selectRaw('count(distinct sales_orders.id) as order_count, sum(sales_orders.total_amount) as total_amount, sum(soi.qty_large) as qty_large, sum(soi.qty_medium) as qty_medium, sum(soi.qty_small) as qty_small')
            ->first();

        // 业务员：用订单冗余的 salesman_name，join items 汇总大中小数量
        $bySalesman = $this->baseSummaryQuery($request)
            ->leftJoin('sales_order_items as soi', 'sales_orders.id', '=', 'soi.sales_order_id')
            ->selectRaw('sales_orders.salesman_id, sales_orders.salesman_name as name, count(distinct sales_orders.id) as order_count, sum(sales_orders.total_amount) as total_amount, sum(soi.qty_large) as qty_large, sum(soi.qty_medium) as qty_medium, sum(soi.qty_small) as qty_small')
            ->groupBy('sales_orders.salesman_id', 'sales_orders.salesman_name')
            ->orderByDesc('order_count')
            ->get();

        // 车辆：join vehicles 取车牌
        $byVehicle = $this->baseSummaryQuery($request)
            ->leftJoin('vehicles', 'sales_orders.vehicle_id', '=', 'vehicles.id')
            ->leftJoin('sales_order_items as soi', 'sales_orders.id', '=', 'soi.sales_order_id')
            ->selectRaw('sales_orders.vehicle_id, vehicles.plate_no as name, count(distinct sales_orders.id) as order_count, sum(sales_orders.total_amount) as total_amount, sum(soi.qty_large) as qty_large, sum(soi.qty_medium) as qty_medium, sum(soi.qty_small) as qty_small')
            ->groupBy('sales_orders.vehicle_id', 'vehicles.plate_no')
            ->orderByDesc('order_count')
            ->get();

        // 客户线路：join customers → routes
        $byRoute = $this->baseSummaryQuery($request)
            ->join('customers', 'sales_orders.customer_id', '=', 'customers.id')
            ->leftJoin('routes', 'customers.route_id', '=', 'routes.id')
            ->leftJoin('sales_order_items as soi', 'sales_orders.id', '=', 'soi.sales_order_id')
            ->selectRaw('customers.route_id, routes.name as name, count(distinct sales_orders.id) as order_count, sum(sales_orders.total_amount) as total_amount, sum(soi.qty_large) as qty_large, sum(soi.qty_medium) as qty_medium, sum(soi.qty_small) as qty_small')
            ->groupBy('customers.route_id', 'routes.name')
            ->orderByDesc('order_count')
            ->get();

        return $this->success([
            'totals' => $totals,
            'bySalesman' => $bySalesman,
            'byVehicle' => $byVehicle,
            'byRoute' => $byRoute,
        ]);
    }

    /** summary 的基础 query（带搜索过滤，不含 select/group） */
    private function baseSummaryQuery(Request $request)
    {
        $query = SalesOrder::query();
        $this->applyOrderFilters($query, $request);

        return $query;
    }

    public function show(SalesOrder $salesOrder)
    {
        $salesOrder->load(['customer', 'warehouse', 'salesman', 'items.product']);

        // 操作历史：对齐旧系统 MpController show 里 $order->logs 回填。
        // order_operation_logs 无独立模型，按 order_id 反查挂到响应上。
        $salesOrder->operation_logs = DB::table('order_operation_logs')
            ->where('order_id', $salesOrder->id)
            ->where('order_type', 'sales_order')
            ->orderBy('id', 'desc')
            ->get(['id', 'action', 'operator_name', 'detail', 'from_status', 'to_status', 'created_at']);

        return $this->success($salesOrder);
    }

    public function store(Request $request)
    {
        $validated = $this->validateOrder($request);

        // 前置校验：正常销售价 + 库存充足（下单即冻结，需先确认冻结得到）
        $errors = $this->validateStockAndPrice($validated['items'], (int) $validated['warehouse_id'], []);
        if ($errors !== []) {
            return $this->error(implode('；', $errors), 422);
        }

        $orderNo = 'SO'.date('YmdHis').strtoupper(Str::random(4));
        DB::beginTransaction();
        try {
            // 注意不能用 AdminUser::value($id, 'real_name')——那会忽略 id 直接取第一条。
            $salesmanId = $validated['salesman_id'] ?? null;
            // 业务员名优先取 auth_user.real_name，取不到回落到 employees.name（前端业务员下拉用 employees 表）
            $salesmanName = null;
            if ($salesmanId) {
                $salesmanName = AdminUser::firstWhere('id', $salesmanId)?->real_name
                    ?: \DB::table('employees')->where('id', $salesmanId)->value('name');
            }

            $order = SalesOrder::create([
                'order_no' => $orderNo,
                'customer_id' => $validated['customer_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'order_date' => $validated['order_date'],
                'salesman_id' => $salesmanId,
                'salesman_name' => $salesmanName,
                'remark' => $validated['remark'] ?? null,
                'status' => 'pending',
                'total_amount' => 0,
                'total_qty' => 0,
                'created_by' => auth('admin')->id(),
            ]);
            $totals = $this->storeItems($order, $validated['items']);
            $order->update($totals);

            // 自动匹配促销（价改/满减/买赠赠品），必须在冻结库存前，赠品行才会一并冻结
            $this->applyPromotion($order, (int) $validated['customer_id']);

            // 下单即冻结库存（对齐旧系统：quantity 扣减 + frozen_qty 累加 + 写 stocks_history）
            $this->freezeItems($order);

            DB::commit();

            // 操作日志在事务提交后写：单据已落库，日志失败不应回滚业务（对齐旧系统 commit 后再 logOperation）
            $this->logOperation($order, '创建订单', '下单'.count($validated['items']).'种商品，总金额：'.number_format((float) $order->total_amount, 2), null, 'pending');

            return $this->created($order->fresh(['customer', 'warehouse', 'salesman', 'items.product']), '订单创建成功');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error('订单创建失败: '.$e->getMessage(), 500);
        }
    }

    /**
     * 正常销售价 + 库存充足的前置校验（对齐旧系统 storeOrder/updateOrder）。
     *
     * $oldFrozen：编辑场景下当前订单已冻结的数量（product_id => 最小单位），
     * 校验时加回——因为保存前会先释放这部分冻结。
     * 返回错误信息数组；空数组表示通过。一次性汇总所有问题，
     * 避免像旧系统那样逐条返回、前端只能看到第一条错误。
     */
    private function validateStockAndPrice(array $items, int $warehouseId, array $oldFrozen): array
    {
        $errors = [];

        foreach ($items as $item) {
            $product = Product::find($item['product_id']);

            // 正常销售模式：三档单价 + 兜底单价 price + amount 全为 0/空 → 拒（赠品/陈列费等允许零价）
            // price 是简单契约（只传 quantity/price）的兜底单价，必须一并算入，
            // 否则会误拒只传 price 的正常销售单。
            if (($item['sale_mode'] ?? '正常销售') === '正常销售') {
                $priceLg = (float) ($item['price_large'] ?? 0);
                $priceMd = (float) ($item['price_medium'] ?? 0);
                $priceSm = (float) ($item['price_small'] ?? 0);
                $priceSimple = (float) ($item['price'] ?? 0);
                $amount = (float) ($item['amount'] ?? 0);
                if ($priceLg <= 0 && $priceMd <= 0 && $priceSm <= 0 && $priceSimple <= 0 && $amount <= 0) {
                    $errors[] = '「'.($product->name ?? '未知商品').'」正常销售模式价格不能为 0 或空';
                }
            }

            // 库存充足：可用 = quantity - frozen_qty，编辑时加回本单已冻结量
            $qty = (int) $this->itemQuantity($item, (int) $item['product_id']);
            $stock = Stock::where('product_id', $item['product_id'])
                ->where('warehouse_id', $warehouseId)
                ->first();
            $available = $stock ? max(0, (int) $stock->quantity - (int) $stock->frozen_qty) : 0;
            $editable = $available + (int) ($oldFrozen[$item['product_id']] ?? 0);
            if ($qty > $editable) {
                $errors[] = '「'.($product->name ?? '未知商品').'」库存不足(可用:'.$editable.', 需要:'.$qty.')';
            }
        }

        return $errors;
    }

    /** 逐行冻结库存（数量取 item.quantity，即已折算的最小单位） */
    private function freezeItems(SalesOrder $order): void
    {
        foreach ($order->items()->get() as $item) {
            $this->stocks->freeze(
                (int) $item->product_id,
                (int) $order->warehouse_id,
                (int) $item->quantity,
                (int) $order->id
            );
        }
    }

    /** 逐行解冻库存（编辑/作废/删除时释放）。$warehouseId 用旧仓库，避免仓库变更后解到错误仓库。 */
    private function unfreezeItems(SalesOrder $order, int $warehouseId): void
    {
        foreach ($order->items()->get() as $item) {
            $this->stocks->unfreeze(
                (int) $item->product_id,
                $warehouseId,
                (int) $item->quantity,
                (int) $order->id
            );
        }
    }

    /**
     * 明细备注清理（对齐旧系统）：非正常销售只保留销售模式名；
     * 正常销售则剥离「赠品|变价|正常销售|陈列费|试用|特价销售|返利」等标记词并压缩空白。
     */
    private function cleanRemark(array $itemData): string
    {
        $saleMode = $itemData['sale_mode'] ?? '正常销售';
        if ($saleMode !== '正常销售') {
            return $saleMode;
        }

        $cleaned = preg_replace('/赠品|变价|正常销售|陈列费|试用|特价销售|返利/', '', $itemData['remark'] ?? '');
        $cleaned = preg_replace('/[,\s;；，；\t]+/u', ' ', $cleaned);

        return trim($cleaned);
    }

    /**
     * 写一条订单操作日志到 order_operation_logs（对齐旧系统 MpController::logOperation）。
     *
     * 字段与旧系统代码实际写入的列一致：旧系统 migration 漏建了 order_no/operator_id/
     * operator_name/detail/from_status/to_status 六列（schema 漂移），新系统 migration 补全。
     * action 统一用中文动作标签；detail 同时写入 detail 与 remark（沿用旧系统约定）。
     */
    private function logOperation(SalesOrder $order, string $action, string $detail, ?string $fromStatus, ?string $toStatus): void
    {
        $admin = auth('admin')->user();

        DB::table('order_operation_logs')->insert([
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'order_type' => 'sales_order',
            'user_id' => $admin?->id,
            'user_name' => $admin?->username,
            'operator_id' => $admin?->id,
            'operator_name' => $admin?->username,
            'action' => $action,
            'action_label' => $action,
            'detail' => mb_substr($detail, 0, 500),
            'remark' => mb_substr($detail, 0, 500),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function update(Request $request, SalesOrder $salesOrder)
    {
        if ($salesOrder->status !== 'pending') {
            return $this->error('只有待配货状态的订单可以编辑', 422);
        }
        $validated = $this->validateOrder($request);

        // 编辑校验：加回当前订单已冻结量（保存前会先释放）
        $oldFrozen = [];
        foreach ($salesOrder->items()->get() as $old) {
            $oldFrozen[$old->product_id] = (int) ($oldFrozen[$old->product_id] ?? 0) + (int) $old->quantity;
        }
        $errors = $this->validateStockAndPrice($validated['items'], (int) $validated['warehouse_id'], $oldFrozen);
        if ($errors !== []) {
            return $this->error(implode('；', $errors), 422);
        }

        // 旧仓库先留一份：释放冻结必须用旧仓库，仓库变更时否则会解到错误仓库
        $oldWarehouseId = (int) $salesOrder->warehouse_id;

        DB::beginTransaction();
        try {
            // 同 store：nullable 字段可能不存在
            $salesmanId = $validated['salesman_id'] ?? null;
            // 业务员名优先取 auth_user.real_name，取不到回落到 employees.name（前端业务员下拉用 employees 表）
            $salesmanName = null;
            if ($salesmanId) {
                $salesmanName = AdminUser::firstWhere('id', $salesmanId)?->real_name
                    ?: \DB::table('employees')->where('id', $salesmanId)->value('name');
            }

            $salesOrder->update([
                'customer_id' => $validated['customer_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'order_date' => $validated['order_date'],
                'salesman_id' => $salesmanId,
                'salesman_name' => $salesmanName,
                'remark' => $validated['remark'] ?? null,
            ]);

            // 先释放旧冻结（用旧仓库），再删旧明细
            $this->unfreezeItems($salesOrder, $oldWarehouseId);
            $salesOrder->items()->delete();
            DB::table('promotion_orders')->where('order_id', $salesOrder->id)->delete();
            $totals = $this->storeItems($salesOrder, $validated['items']);
            $salesOrder->update($totals);

            // 重算促销（价改/满减/买赠赠品），在重新冻结前
            $this->applyPromotion($salesOrder, (int) $validated['customer_id']);

            // 按新仓库重新冻结
            $this->freezeItems($salesOrder);

            DB::commit();

            $this->logOperation($salesOrder, '编辑订单', '编辑订单，'.count($validated['items']).'种商品', 'pending', 'pending');

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
            'salesman_id' => 'nullable|integer',
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
                'remark' => $this->cleanRemark($itemData),
            ]);

            $totalAmount += $amount;
            $totalQty += $quantity;
        }

        return ['total_amount' => round($totalAmount, 2), 'total_qty' => $totalQty];
    }

    /**
     * 下单后自动匹配促销（在 storeItems 之后、freezeItems 之前调用）：
     *  - 商品级折扣/特价：覆盖明细小单位单价，重算明细金额，备注加促销标签；
     *  - 满减：按档位减免，记入订单 discount_amount；
     *  - 买赠：追加零价赠品明细行（sale_mode=赠品），会一并被冻结库存；
     *  - 命中的促销写入 promotion_orders，供效果报表。
     *
     * 注意：商品折扣已体现在明细金额里，这里只额外减满减金额，避免重复扣减。
     */
    private function applyPromotion(SalesOrder $order, int $customerId): void
    {
        $origTotal = round((float) $order->total_amount, 2);

        $rows = $order->items()->get()->map(fn ($i) => [
            'product_id' => (int) $i->product_id,
            'qty' => (int) $i->quantity,
            'price' => (float) $i->price_small,
        ])->all();

        $result = $this->promotions->calculate($customerId, $rows);
        if (empty($result['applied'])) {
            return;
        }

        // 1) 商品级价改：覆盖小单位单价（price=price_small），重算金额
        $overrideMap = collect($result['item_overrides'])->keyBy('product_id');
        $newTotal = 0.0;
        foreach ($order->items()->get() as $it) {
            $ov = $overrideMap->get((int) $it->product_id);
            if ($ov) {
                $promoPrice = round((float) $ov['promo_price'], 2);
                $it->price_small = $promoPrice;
                $it->price = $promoPrice;
                $it->amount = round(
                    (float) $it->qty_large * (float) $it->price_large
                    + (float) $it->qty_medium * (float) $it->price_medium
                    + (float) $it->qty_small * $promoPrice, 2);
                $label = $ov['label'] ?? '';
                if ($label && ! str_contains((string) $it->remark, $label)) {
                    $it->remark = trim(trim((string) $it->remark).' '.$label);
                }
                $it->price_source = '特殊';
                $it->save();
            }
            $newTotal += (float) $it->amount;
        }

        // 2) 赠品行
        foreach ($result['gift_lines'] as $g) {
            $order->items()->create([
                'product_id' => (int) $g['product_id'],
                'quantity' => (int) $g['qty'],
                'qty_small' => (int) $g['qty'],
                'price' => 0, 'price_small' => 0, 'price_large' => 0, 'price_medium' => 0,
                'amount' => 0,
                'sale_mode' => '赠品',
                'price_source' => '特殊',
                'remark' => '赠品',
            ]);
        }

        // 3) 满减金额（商品折扣已在 newTotal 里扣过，这里只补满减）
        $reductionDiscount = 0.0;
        foreach ($result['applied'] as $ap) {
            if (($ap['type'] ?? '') === 'full_reduction') {
                $reductionDiscount += (float) $ap['amount'];
            }
        }
        $reductionDiscount = round($reductionDiscount, 2);

        // 4) 写促销使用记录
        foreach ($result['applied'] as $ap) {
            DB::table('promotion_orders')->insert([
                'promotion_id' => $ap['promotion_id'],
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'customer_id' => $customerId,
                'discount_amount' => round((float) ($ap['amount'] ?? 0), 2),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $productDiscount = round(max(0, $origTotal - $newTotal), 2);
        $order->discount_amount = round($productDiscount + $reductionDiscount, 2);
        $order->total_amount = round(max(0, $newTotal - $reductionDiscount), 2);
        $order->save();
    }

    public function destroy(SalesOrder $salesOrder)
    {
        if ($salesOrder->status !== 'pending') {
            return $this->error('只有待配货状态的订单可以删除', 422);
        }

        DB::beginTransaction();
        try {
            // 先释放冻结再删单，否则库存永久泄漏
            $this->unfreezeItems($salesOrder, (int) $salesOrder->warehouse_id);
            // 日志在删除前记（同事务）：单据删除后历史仍可按 order_id/order_no 回溯
            $this->logOperation($salesOrder, '删除订单', '删除待配货订单', 'pending', null);
            $salesOrder->delete();
            DB::commit();

            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error('删除失败: '.$e->getMessage(), 500);
        }
    }

    /**
     * 推进订单状态流转：待配货→配货中→待配送→配送中→已收款/待收款。
     * target 指定目标状态，不传则按顺序推进一档。
     */
    public function approve(Request $request, SalesOrder $salesOrder)
    {
        $flow = ['pending' => '配货中', '配货中' => '待调度', '待调度' => '待配送', '待配送' => '配送中', '配送中' => '已收款'];
        $current = $salesOrder->status;
        $target = $request->input('target');
        if (! $target) {
            $target = $flow[$current] ?? null;
        }
        if (! $target || $target === $current) {
            return $this->error('当前状态无法流转（'.$current.'）', 422);
        }
        // 正向 + 逆向流转都允许（逆向：回退到上一状态）
        $allowed = [
            'pending' => ['配货中'],
            '配货中' => ['待调度', 'pending'],
            '待调度' => ['待配送', '配货中'],
            '待配送' => ['配送中', '待调度'],
            '配送中' => ['已收款', '待收款', '待配送'],
            '待收款' => ['配送中', '已收款'],
            '已收款' => ['待收款'],
        ];
        if (! in_array($target, $allowed[$current] ?? [], true)) {
            return $this->error('不能从 '.$current.' 流转到 '.$target, 422);
        }
        try {
            $updateData = ['status' => $target];
            // 待调度→待配送：调度时保存配送员和车辆
            if ($target === '待配送' && $request->filled('delivery_person_id')) {
                $updateData['delivery_person_id'] = (int) $request->input('delivery_person_id');
            }
            if ($target === '待配送' && $request->filled('vehicle_id')) {
                $updateData['vehicle_id'] = (int) $request->input('vehicle_id');
            }
            $salesOrder->update($updateData);

            // 配送中→已收款/待收款：自动生成应收款记录（订单与财务联动）
            if (in_array($target, ['已收款', '待收款'], true) && $current === '配送中') {
                \DB::table('receives')->insert([
                    'receive_no' => 'AR'.$date('YmdHis').strtoupper(\Str::random(4)),
                    'receive_type' => 1,
                    'customer_id' => $salesOrder->customer_id,
                    'sales_order_id' => $salesOrder->id,
                    'amount' => (float) $salesOrder->total_amount - (float) $salesOrder->paid_amount,
                    'receive_date' => now()->toDateString(),
                    'payment_method' => '应收',
                    'status' => 0, // 待确认
                    'remark' => '订单发货自动生成应收款：'.$salesOrder->order_no,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            try {
                $this->logOperation($salesOrder, '状态流转', $current.' → '.$target, $current, $target);
            } catch (\Throwable $e) {
                \Log::warning('logOperation failed: '.$e->getMessage());
            }

            return $this->success($salesOrder->fresh(['customer', 'warehouse', 'salesman', 'items.product']), '状态已更新为'.$target);
        } catch (\Exception $e) {
            return $this->error('状态流转失败: '.$e->getMessage(), 500);
        }
    }

    public function cancel(SalesOrder $salesOrder)
    {
        // 待配货/配货中可作废（已配送的不行，库存已动）
        if (! in_array($salesOrder->status, ['pending', '配货中'], true)) {
            return $this->error('只有待配货/配货中状态的订单可以作废', 422);
        }

        DB::beginTransaction();
        try {
            // 作废即释放冻结（对齐旧系统 cancel：恢复库存 + 释放冻结）
            $this->unfreezeItems($salesOrder, (int) $salesOrder->warehouse_id);
            $salesOrder->update(['status' => 'cancelled']);
            DB::commit();

            $this->logOperation($salesOrder, '取消订单', '订单作废，释放冻结库存', 'pending', 'cancelled');

            return $this->success(null, '取消成功');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error('取消失败: '.$e->getMessage(), 500);
        }
    }

    /**
     * 批量红冲：撤单(cancel) 或 改单(modify)
     * - 撤单：原订单标已红冲，生成负金额冲销单
     * - 改单：撤单 + 复制原订单生成新草稿单
     * 需要红冲原因（财务审计）
     */
    public function batchRedFlush(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|exists:sales_orders,id',
            'type' => 'required|in:cancel,modify',
            'reason' => 'required|string|max:500',
        ]);

        $adminId = auth('admin')->id();
        $results = [];

        DB::beginTransaction();
        try {
            foreach ($validated['ids'] as $orderId) {
                $order = SalesOrder::find($orderId);
                // 校验：只有已收款/待收款状态可红冲
                if (! in_array($order->status, ['配送中', '已收款', '待收款'], true)) {
                    $results[] = ['id' => $orderId, 'success' => false, 'message' => "状态{$order->status}不可红冲"];

                    continue;
                }

                // 原订单标记已红冲，关联红字冲销单
                $order->update([
                    'status' => '已红冲',
                    'red_flush_reason' => $validated['reason'],
                    'red_flush_by' => $adminId,
                    'red_flush_at' => now(),
                ]);

                // 生成红字冲销单（负金额，关联原订单）
                $flushOrder = SalesOrder::create([
                    'order_no' => 'RF'.date('YmdHis').strtoupper(Str::random(4)),
                    'order_type' => $order->order_type,
                    'customer_id' => $order->customer_id,
                    'warehouse_id' => $order->warehouse_id,
                    'order_date' => now()->toDateString(),
                    'total_amount' => -abs((float) $order->total_amount),
                    'total_qty' => 0,
                    'status' => '已红冲',
                    'salesman_id' => $order->salesman_id,
                    'salesman_name' => $order->salesman_name,
                    'created_by' => $adminId,
                    'remark' => '红字冲销单：'.$validated['reason'],
                    'original_order_id' => $orderId,
                    'payment_status' => '未确认',
                ]);

                // 原订单反向关联红字冲销单（撤单时 = 冲销单ID；改单时 = 新订单ID）
                if ($validated['type'] === 'cancel') {
                    $order->update(['red_flush_order_id' => $flushOrder->id]);

                    // 红冲财务联动：取消或标记应收款记录
                    $receiveRecord = DB::table('receives')->where('sales_order_id', $orderId)->first();
                    if ($receiveRecord) {
                        if ($receiveRecord->status == 0) {
                            // 待确认的应收款直接删除
                            DB::table('receives')->where('id', $receiveRecord->id)->delete();
                        } else {
                            // 已确认的应收款标记为已红冲
                            DB::table('receives')->where('id', $receiveRecord->id)->update([
                                'status' => 2,
                                'amount' => 0,
                                'remark' => '订单红冲撤单，应收款已取消：'.$validated['reason'],
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }

                $newOrderId = null;
                // 改单：复制原订单生成新草稿
                if ($validated['type'] === 'modify') {
                    $newOrder = $order->replicate();
                    $newOrder->order_no = 'SO'.date('YmdHis').strtoupper(Str::random(4));
                    $newOrder->status = 'pending';
                    $newOrder->total_amount = 0;
                    $newOrder->total_qty = 0;
                    $newOrder->original_order_id = $orderId;
                    $newOrder->red_flush_reason = null;
                    $newOrder->red_flush_by = null;
                    $newOrder->red_flush_at = null;
                    $newOrder->save();
                    // 复制明细
                    foreach ($order->items as $item) {
                        $newItem = $item->replicate();
                        $newItem->sales_order_id = $newOrder->id;
                        $newItem->save();
                    }
                    $newOrderId = $newOrder->id;
                    $order->update(['red_flush_order_id' => $newOrderId]);

                    // 红冲财务联动：改单时创建新的应收款记录给新订单
                    $originalReceiveAmount = (float) $order->total_amount - (float) $order->paid_amount;
                    if ($originalReceiveAmount > 0) {
                        DB::table('receives')->insert([
                            'receive_no' => 'AR'.date('YmdHis').strtoupper(Str::random(4)),
                            'receive_type' => 1,
                            'customer_id' => $order->customer_id,
                            'sales_order_id' => $newOrderId,
                            'amount' => $originalReceiveAmount,
                            'receive_date' => now()->toDateString(),
                            'payment_method' => '应收',
                            'status' => 0,
                            'remark' => '订单红冲改单新生成应收款：'.$newOrder->order_no,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                $results[] = ['id' => $orderId, 'success' => true, 'flush_order_id' => $flushOrder->id, 'new_order_id' => $newOrderId];
            }

            DB::commit();

            return $this->success(['results' => $results], '红冲完成');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error('红冲失败: '.$e->getMessage(), 500);
        }
    }

    /** 打印：递增 print_count，返回订单详情供前端打印 */
    public function print(SalesOrder $salesOrder)
    {
        $salesOrder->increment('print_count');
        $salesOrder->load(['customer', 'warehouse', 'salesman', 'items.product']);

        return $this->success($salesOrder, '打印准备就绪');
    }

    public function statistics()
    {
        $stats = [
            'total_orders' => SalesOrder::count(),
            'total_amount' => SalesOrder::sum('total_amount'),
            'pending' => SalesOrder::where('status', 'pending')->count(),
            'approved' => SalesOrder::where('status', 'approved')->count(),
        ];

        return response()->json(['data' => $stats]);
    }
}
