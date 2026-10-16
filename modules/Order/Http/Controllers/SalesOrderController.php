<?php

namespace Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Inbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User as AdminUser;
use Modules\Member\Models\Member;
use Modules\Member\Services\MemberService;
use Modules\Order\Models\Customer;
use Modules\Order\Models\SalesOrder;
use Modules\Order\Services\PromotionService;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\Stock;
use Modules\Stock\Models\Warehouse;
use Modules\Stock\Services\StockService;

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
            $base = (float) ($data['quantity'] ?? 0) * (float) ($data['price'] ?? 0);

            return round($this->applyTaxAndDiscount($base, $data), 2);
        }

        $base = (float) ($data['qty_large'] ?? 0) * (float) ($data['price_large'] ?? 0)
            + (float) ($data['qty_medium'] ?? 0) * (float) ($data['price_medium'] ?? 0)
            + (float) ($data['qty_small'] ?? 0) * (float) ($data['price_small'] ?? 0);

        return round($this->applyTaxAndDiscount($base, $data), 2);
    }

    /**
     * 价税分离 + 折扣：amount = base + base×tax_rate% − base×discount_rate%。
     *
     * 税率按不含税价计税（价税分离），折扣按原价打折后减——两项都以 base 为基数，
     * 不做复合（与前端 calcAmount 同口径：base + tax − discount）。
     * tax_rate/discount_rate 缺省 0，等价于原价，旧单不受影响。
     */
    private function applyTaxAndDiscount(float $base, array $data): float
    {
        $amount = $base;
        $taxRate = (float) ($data['tax_rate'] ?? 0);
        if ($taxRate > 0) {
            $amount += $base * ($taxRate / 100);
        }
        $discountRate = (float) ($data['discount_rate'] ?? 0);
        if ($discountRate > 0) {
            $amount -= $base * ($discountRate / 100);
        }

        return $amount;
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
            // 红冲单在 order_type 上继承原单（见 batchRedFlush），只能靠 original_order_id 识别
            $o->is_flush = ! empty($o->original_order_id);
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

        // type_counts：订单类型分布。按列的实际取值分组原样返回，前端自己映射——
        // 不在此硬编码取值（store() 靠迁移默认值、红冲单继承原单类型，
        // 一旦新增类型或回填历史数据，写死的分支就会漏）。
        // 剔除 order_type 后重新统计，否则切到某个 tab 后其他 tab 计数会被清 0。
        $typeBaseQuery = SalesOrder::query();
        $this->applyOrderFiltersWithout($typeBaseQuery, $request, ['order_type']);
        $typeCountRaw = $typeBaseQuery->select('order_type', \DB::raw('count(*) as cnt'))->groupBy('order_type')->pluck('cnt', 'order_type');
        $typeCounts = [
            'all' => $typeCountRaw->sum(),
            'raw' => $typeCountRaw->toArray(),
        ];

        // quick_counts：快捷筛选分布（剔除 quick_filter 后统计）
        $quickBaseQuery = SalesOrder::query();
        $this->applyOrderFiltersWithout($quickBaseQuery, $request, ['quick_filter']);
        $quickAll = (clone $quickBaseQuery)->count();
        $quickUnprinted = (clone $quickBaseQuery)->where('print_count', 0)->count();
        $quickSpecial = (clone $quickBaseQuery)->whereHas('items', fn ($q) => $q->where('price_source', '特殊')->whereNotIn('sale_mode', ['赠品', '陈列费']))->count();
        $quickGift = (clone $quickBaseQuery)->whereHas('items', fn ($q) => $q->whereIn('sale_mode', ['赠品', '陈列费']))->count();
        $quickRemark = (clone $quickBaseQuery)->whereNotNull('remark')->where('remark', '!=', '')->count();
        $quickCounts = [
            'all' => $quickAll,
            'unprinted' => $quickUnprinted,
            'special' => $quickSpecial,
            'gift' => $quickGift,
            'remark' => $quickRemark,
        ];

        // customers/warehouses/salesmen 只首次加载（后续翻页不传，减少数据量）
        $extra = [];
        if (! $request->has('page') || $request->integer('page', 1) === 1) {
            $extra['customers'] = Customer::where('is_active', true)->orderBy('name')->get(['id', 'name']);
            $extra['warehouses'] = Warehouse::where('is_active', true)->where('type', 'normal')->get(['id', 'name']);
            $extra['salesmen'] = \DB::table('employees')->orderBy('id')->get(['id', 'name']);
        }

        return $this->success(array_merge([
            'list' => $list,
            'total' => $orders->total(),
            'page' => $orders->currentPage(),
            'page_size' => $orders->perPage(),
            'last_page' => $orders->lastPage(),
            'status_counts' => $counts,
            'type_counts' => $typeCounts,
            'quick_counts' => $quickCounts,
        ], $extra));
    }

    /**
     * 与 applyOrderFilters 等价，但跳过指定字段（用于聚合统计时「不带自身维度」）。
     * 例如统计类型分布时剔除 order_type，统计快捷筛选分布时剔除 quick_filter。
     *
     * @param  array  $skip  要跳过的字段名
     */
    private function applyOrderFiltersWithout($query, Request $request, array $skip): void
    {
        $skip = array_flip($skip);

        if (! isset($skip['keyword']) && $request->filled('keyword')) {
            $query->where('order_no', 'like', '%'.$request->keyword.'%');
        }
        if (! isset($skip['status']) && $request->filled('status')) {
            $query->where('status', $request->status);
        }
        if (! isset($skip['customer_id']) && $request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if (! isset($skip['salesman_id']) && $request->filled('salesman_id')) {
            $query->where('salesman_id', $request->salesman_id);
        }
        if (! isset($skip['vehicle_id']) && $request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }
        if (! isset($skip['route_id']) && $request->filled('route_id')) {
            $query->whereHas('customer', fn ($q) => $q->where('route_id', $request->route_id));
        }
        if (! isset($skip['order_type'])) {
            $orderType = $request->input('order_type', 'all');
            if ($orderType !== 'all') {
                $query->where('order_type', $orderType);
            }
        }
        if (! isset($skip['status_tab'])) {
            $statusTab = $request->input('status_tab');
            $tabStatusMap = ['1' => 'pending', '2' => '配货中', '6' => '待调度', '3' => '待配送', '7' => '配送中'];
            if (isset($tabStatusMap[$statusTab])) {
                $query->where('status', $tabStatusMap[$statusTab]);
            } elseif ($statusTab === '4') {
                $query->whereIn('status', ['已收款', '待收款']);
            }
        }
        if (! isset($skip['quick_filter'])) {
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
        }
        if (! isset($skip['quick_date'])) {
            $quickDate = $request->input('quick_date');
            if ($quickDate === 'today') {
                $query->whereDate('order_date', now()->toDateString());
            } elseif ($quickDate === 'yesterday') {
                $query->whereDate('order_date', now()->subDay()->toDateString());
            } elseif ($quickDate === 'week') {
                $query->whereDate('order_date', '>=', now()->subDays(6)->toDateString());
            } elseif ($quickDate === 'month') {
                $query->whereMonth('order_date', now()->month)->whereYear('order_date', now()->year);
            } elseif ($quickDate === 'last_month') {
                $lastMonth = now()->subMonth();
                $query->whereMonth('order_date', $lastMonth->month)->whereYear('order_date', $lastMonth->year);
            }
        }
        if (! isset($skip['start_date']) && $request->filled('start_date')) {
            $query->whereDate('order_date', '>=', $request->start_date);
        }
        if (! isset($skip['end_date']) && $request->filled('end_date')) {
            $query->whereDate('order_date', '<=', $request->end_date);
        }
    }

    /**
     * 订单列表/汇总共用的搜索条件：keyword(订单号) / status / customer_id
     * + 左侧汇总联动筛选 salesman_id / vehicle_id / route_id(按客户线路)
     * + 订单类型筛选（order_type）与快捷筛选计数共用此方法做聚合
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
        // 订单类型：all / normal / return / ...；前端按 order_type 字段区分
        $orderType = $request->input('order_type', 'all');
        if ($orderType !== 'all') {
            $query->where('order_type', $orderType);
        }
        // 顶部状态 tab：1待配货 2配货中 6待调度 3待配送 7配送中 4已发货收款 5全部
        $statusTab = $request->input('status_tab');
        $tabStatusMap = ['1' => 'pending', '2' => '配货中', '6' => '待调度', '3' => '待配送', '7' => '配送中'];
        if (isset($tabStatusMap[$statusTab])) {
            $query->where('status', $tabStatusMap[$statusTab]);
        } elseif ($statusTab === '4') {
            $query->whereIn('status', ['已收款', '待收款']);
        } elseif ($statusTab === '5') {
            // 全部单据：不追加过滤
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
        // 日期筛选：quick_date（today/yesterday/week/month/last_month/custom）或 start_date/end_date
        $quickDate = $request->input('quick_date');
        if ($quickDate === 'today') {
            $query->whereDate('order_date', now()->toDateString());
        } elseif ($quickDate === 'yesterday') {
            $query->whereDate('order_date', now()->subDay()->toDateString());
        } elseif ($quickDate === 'week') {
            $query->whereDate('order_date', '>=', now()->subDays(6)->toDateString());
        } elseif ($quickDate === 'month') {
            $query->whereMonth('order_date', now()->month)->whereYear('order_date', now()->year);
        } elseif ($quickDate === 'last_month') {
            $lastMonth = now()->subMonth();
            $query->whereMonth('order_date', $lastMonth->month)->whereYear('order_date', $lastMonth->year);
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
            ->leftJoin('products as p', 'soi.product_id', '=', 'p.id')
            ->selectRaw('count(distinct sales_orders.id) as order_count, sum(sales_orders.total_amount) as total_amount, sum(soi.qty_large) as qty_large, sum(soi.qty_medium) as qty_medium, sum(soi.qty_small) as qty_small')
            ->first();

        // 重量/体积：明细数量 × 商品单件规格。商品未维护规格时按 0 计，不阻塞汇总
        $weightVolume = $this->baseSummaryQuery($request)
            ->leftJoin('sales_order_items as soi', 'sales_orders.id', '=', 'soi.sales_order_id')
            ->leftJoin('products as p', 'soi.product_id', '=', 'p.id')
            ->selectRaw('sum(soi.quantity * coalesce(p.weight, 0)) as total_weight, sum(soi.quantity * coalesce(p.volume, 0)) as total_volume')
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
            'totals' => array_merge((array) $totals->toArray(), [
                'total_weight' => (float) ($weightVolume->total_weight ?? 0),
                'total_volume' => (float) ($weightVolume->total_volume ?? 0),
            ]),
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

    /**
     * 最近成交价：取该客户上次成交该商品的单价（按 order_date 倒序）。
     *
     * 「最近价」开关打开时，前端选完商品后调这里批量取价——
     * 只取「正常销售」明细（赠品/陈列费等清零价不算最近价），
     * 排除红冲单（original_order_id 非空）与已作废单。
     * 退货单不在 sales_orders（见 sales_returns 表），天然不会命中。
     * 一次查一个客户+多个商品，避免逐行 N 次请求。
     *
     * @return array product_id => ['price_small','price_medium','price_large','order_no','order_date']
     */
    public function recentPrices(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'integer|exists:products,id',
        ]);

        // 按 order_date 倒序取全部命中行，PHP 侧每商品只留第一条 = 最近一次成交价。
        // 不用窗口函数（ROW_NUMBER 需 MySQL 8），保持与生产 MySQL 5.x 兼容。
        $rows = DB::table('sales_order_items as soi')
            ->join('sales_orders as so', 'soi.sales_order_id', '=', 'so.id')
            ->selectRaw('soi.product_id, soi.price_small, soi.price_medium, soi.price_large, so.order_no, so.order_date')
            ->where('so.customer_id', (int) $validated['customer_id'])
            ->whereIn('soi.product_id', $validated['product_ids'])
            ->where('soi.sale_mode', '正常销售')
            ->whereNull('so.original_order_id')
            ->where(function ($q) {
                $q->where('so.status', '!=', '已作废')->orWhereNull('so.status');
            })
            ->orderBy('so.order_date', 'desc')
            ->orderBy('so.id', 'desc')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            if (! isset($result[$row->product_id])) {
                $result[$row->product_id] = [
                    'price_small' => $row->price_small,
                    'price_medium' => $row->price_medium,
                    'price_large' => $row->price_large,
                    'order_no' => $row->order_no,
                    'order_date' => (string) $row->order_date,
                ];
            }
        }

        return $this->success($result);
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

        // ---------------------------------------------------------------- 重复提交防护
        //
        // 业务反馈「多人同时点会卡死，且存在重复提交风险」。前端已加按钮锁
        // （submitting 早退），但那只管单标签页：回车+点击同时触发、弱网下
        // 浏览器自动重发、用户以为卡住后退重进再点一次，都会产生第二个
        // 完全相同的 POST。这类重复订单在业务上极难察觉（单号不同、日期相同、
        // 明细一模一样），却会造成重复冻结库存。
        //
        // 这里用前端带来的 client_token 做幂等键：
        //   同一 token 的第二次请求直接返回首次创建的订单，不再落库。
        // 用 Cache 而不是新建表：本场景只需覆盖「秒级重发」窗口，不需要永久留痕；
        // 复用已有的 Redis，无需迁移、无需清理任务。
        // 取不到 token（老前端/第三方调用）时行为不变，照常创建。
        $clientToken = trim((string) ($request->input('client_token') ?? ''));
        $idempotencyKey = $clientToken !== ''
            ? 'sales_order:create:'.auth('admin')->id().':'.$clientToken
            : '';

        if ($idempotencyKey !== '') {
            $cached = Cache::get($idempotencyKey);
            if (is_array($cached) && isset($cached['id'])) {
                $existing = SalesOrder::with(['customer', 'warehouse', 'salesman', 'items.product'])->find($cached['id']);
                if ($existing) {
                    // 幂等命中：返回首单，不重复创建、不重复冻结库存
                    return $this->created($existing, '订单已创建（重复提交已拦截，未重复下单）');
                }
            }
            // 抢占：拿不到锁说明另一个同样的请求正在处理中，直接挡回，
            // 让前端等到第一次的结果，而不是并发写两张单。
            if (! Cache::add($idempotencyKey.'_lock', 1, 30)) {
                return $this->error('订单正在提交中，请勿重复点击', 429);
            }
        }

        // 前置校验：正常销售价 + 库存充足（下单即冻结，需先确认冻结得到）
        $errors = $this->validateStockAndPrice($validated['items'], (int) $validated['warehouse_id'], []);
        if ($errors !== []) {
            if ($idempotencyKey !== '') {
                Cache::forget($idempotencyKey.'_lock');
            }

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
                'order_type' => $validated['order_type'] ?? 'normal',
                'customer_id' => $validated['customer_id'],
                // 会员身份快照：下单时绑定，后续会员变动不影响历史订单。
                // 这里只记录「申请」的储值/积分抵扣金额，真正扣减在结算时（流转到「已收款/待收款」）才发生。
                'member_id' => $validated['member_id'] ?? null,
                'use_balance' => round((float) ($validated['use_balance'] ?? 0), 2),
                'use_points' => (int) ($validated['use_points'] ?? 0),
                'warehouse_id' => $validated['warehouse_id'],
                'order_date' => $validated['order_date'],
                'delivery_date' => $validated['delivery_date'] ?? null,
                'dispatch_date' => $validated['dispatch_date'] ?? null,
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'reconcile_date' => $validated['reconcile_date'] ?? null,
                'print_type' => $validated['print_type'] ?? 'none',
                'sort_type' => $validated['sort_type'] ?? 'entry',
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
            // 「冻结库存」开关关闭时跳过——录入期不占库存，交配送环节再冻
            if ($validated['freeze_stock'] ?? true) {
                $this->freezeItems($order);
            }

            DB::commit();

            // 幂等键落定：记下 token → 订单 id，有效期 10 分钟。
            // 这段时间内任何带同一 token 的重发都直接返回这张单，不再新建。
            // 必须在 commit 之后写——事务里写的话，一旦回滚就会出现
            // 「缓存指向一张并不存在的订单」，重发时查到空记录又走到创建分支。
            if ($idempotencyKey !== '') {
                Cache::put($idempotencyKey, ['id' => $order->id, 'order_no' => $order->order_no], 600);
            }

            // 操作日志在事务提交后写：单据已落库，日志失败不应回滚业务（对齐旧系统 commit 后再 logOperation）
            $this->logOperation($order, '创建订单', '下单'.count($validated['items']).'种商品，总金额：'.number_format((float) $order->total_amount, 2), null, 'pending');

            return $this->created($order->fresh(['customer', 'warehouse', 'salesman', 'items.product']), '订单创建成功');
        } catch (\Exception $e) {
            DB::rollBack();

            // 失败要释放抢占锁，否则用户在 30 秒内重试会被自己的锁挡住，
            // 报「正在提交中」却根本没有请求在处理。
            if ($idempotencyKey !== '') {
                Cache::forget($idempotencyKey.'_lock');
            }

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
                'order_type' => $validated['order_type'] ?? 'normal',
                'customer_id' => $validated['customer_id'],
                // 只有「待配货」单可编辑，此时尚未结算，改会员/抵扣不会破坏 points_settled 幂等。
                'member_id' => $validated['member_id'] ?? null,
                'use_balance' => round((float) ($validated['use_balance'] ?? 0), 2),
                'use_points' => (int) ($validated['use_points'] ?? 0),
                'warehouse_id' => $validated['warehouse_id'],
                'order_date' => $validated['order_date'],
                'delivery_date' => $validated['delivery_date'] ?? null,
                'dispatch_date' => $validated['dispatch_date'] ?? null,
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'reconcile_date' => $validated['reconcile_date'] ?? null,
                'print_type' => $validated['print_type'] ?? 'none',
                'sort_type' => $validated['sort_type'] ?? 'entry',
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

            // 按新仓库重新冻结（开关关闭时跳过，保持与 store 同口径）
            if ($validated['freeze_stock'] ?? true) {
                $this->freezeItems($salesOrder);
            }

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
            'delivery_date' => 'nullable|date',
            'dispatch_date' => 'nullable|date',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'reconcile_date' => 'nullable|date',
            'salesman_id' => 'nullable|integer',
            'print_type' => 'nullable|string|max:20|in:none,item_note,delivery_note',
            'sort_type' => 'nullable|string|max:20|in:code,name,entry',
            'freeze_stock' => 'nullable|boolean',
            'order_type' => 'nullable|string|max:20|in:normal,process,exchange,give_back',
            // 会员管理模块：会员身份 + 储值/积分抵扣。
            // 必须在这里声明，否则 validate() 只返回规则里的键，字段会被静默丢弃。
            'member_id' => 'nullable|exists:members,id',
            'use_balance' => 'nullable|numeric|min:0',
            'use_points' => 'nullable|integer|min:0',
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
            // 扩展字段：production_date/tax_rate/discount_rate 必须在这里声明，
            // 否则 $request->validate() 只返回规则里出现过的键，三个字段会被静默丢弃，
            // storeItems 里拿到的永远是 null/0（前端勾了开关也不生效）。
            'items.*.production_date' => 'nullable|date',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.discount_rate' => 'nullable|numeric|min:0|max:100',
            // 幂等键：前端每次「新增」生成一次，重发时原样带回。
            // 限长 64——它只用来做缓存键，不该被塞进任意长度的字符串。
            'client_token' => 'nullable|string|max:64',
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
                'production_date' => $itemData['production_date'] ?? null,
                'tax_rate' => round((float) ($itemData['tax_rate'] ?? 0), 2),
                'discount_rate' => round((float) ($itemData['discount_rate'] ?? 0), 2),
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
     * 批量轻量更新：只改备注/对账日期等表头字段，不动明细与库存冻结。
     * 与 update()（全量编辑，重算明细+重冻结）区分开——批量备注走这里，
     * 避免 N 单全量更新触发明细重算和库存反复冻解。
     */
    public function batchUpdate(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'remark' => 'nullable|string|max:500',
            'reconcile_date' => 'nullable|date',
        ]);

        $update = array_filter([
            'remark' => $validated['remark'] ?? null,
            'reconcile_date' => $validated['reconcile_date'] ?? null,
        ], fn ($v) => $v !== null);

        if (empty($update)) {
            return $this->error('没有可更新的字段', 422);
        }

        $count = SalesOrder::whereIn('id', $validated['ids'])->update($update + ['updated_at' => now()]);

        return $this->success(['updated' => $count], "已更新 {$count} 条订单");
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
        // 整个流转包在一个事务里：状态更新、应收款写入、会员结算必须原子。
        // 若会员结算失败（余额/积分不足等），状态更新与应收款写入一并回滚，
        // 避免「订单状态未推进、但应收款已生成」或重试时重复生成应收款。
        DB::beginTransaction();

        try {
            $updateData = ['status' => $target];
            // 待调度→待配送：调度时保存配送员和车辆
            if ($target === '待配送' && $request->filled('delivery_person_id')) {
                $updateData['delivery_person_id'] = (int) $request->input('delivery_person_id');
            }
            if ($target === '待配送' && $request->filled('vehicle_id')) {
                $updateData['vehicle_id'] = (int) $request->input('vehicle_id');
            }
            // 待配货→配货中：记录备货人（谁点的配货），列表「备货人」列取此值
            if ($target === '配货中' && $current === 'pending') {
                $admin = auth('admin')->user();
                if ($admin) {
                    $updateData['stocker_id'] = $admin->id;
                    $updateData['stocker_name'] = $admin->real_name ?? $admin->username ?? null;
                }
            }
            // 对账日期可由前端在任意流转时带入
            if ($request->filled('reconcile_date')) {
                $updateData['reconcile_date'] = $request->input('reconcile_date');
            }
            $salesOrder->update($updateData);

            // 配送中→已收款/待收款：先做会员储值/积分结算，再按扣除后的净额生成应收款。
            // 顺序不能反：应收款金额必须减去会员已抵扣的储值+积分，否则会多收钱。
            // settleMemberPoints 抛异常时由外层 catch 统一回滚（含上面的状态更新）。
            // points_settled 幂等标记：避免 approve 重复触发导致重复扣分/重复加分。
            if (in_array($target, ['已收款', '待收款'], true) && ! $salesOrder->points_settled) {
                $this->settleMemberPoints($salesOrder);
            }

            // 配送中→已收款/待收款：自动生成应收款记录（订单与财务联动）
            // 金额 = 订单总额 - 已收款 - 会员储值抵扣 - 会员积分抵扣
            if (in_array($target, ['已收款', '待收款'], true) && $current === '配送中') {
                $receiveAmount = round(
                    (float) $salesOrder->total_amount
                    - (float) $salesOrder->paid_amount
                    - (float) $salesOrder->use_balance
                    - MemberService::pointsToAmount(
                        (int) $salesOrder->use_points,
                        ['amount' => (float) $salesOrder->total_amount]
                    ),
                    2
                );

                if ($receiveAmount > 0) {
                    \DB::table('receives')->insert([
                        'receive_no' => 'AR'.date('YmdHis').strtoupper(\Str::random(4)),
                        'receive_type' => 1,
                        'customer_id' => $salesOrder->customer_id,
                        'sales_order_id' => $salesOrder->id,
                        'amount' => $receiveAmount,
                        'receive_date' => now()->toDateString(),
                        'payment_method' => '应收',
                        'status' => 0, // 待确认
                        'remark' => '订单发货自动生成应收款：'.$salesOrder->order_no,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error('状态流转失败：'.$e->getMessage(), 422);
        }

        try {
            $this->logOperation($salesOrder, '状态流转', $current.' → '.$target, $current, $target);
        } catch (\Throwable $e) {
            \Log::warning('logOperation failed: '.$e->getMessage());
        }

        // 订单审核通过（pending → 配货中）通知配货员去配货。
        // 在事务外、logOperation 之后发：通知失败不影响状态流转，也不阻断日志。
        if ($target === '配货中') {
            $this->notifyPickers($salesOrder);
        }

        return $this->success($salesOrder->fresh(['customer', 'warehouse', 'salesman', 'items.product']), '状态已更新为'.$target);
    }

    /**
     * 订单进入「配货中」时通知配货员。
     *
     * 配货员 = 持有 delivery.pick（拣货）或 order.dispatch（配货/调度）权限的用户——
     * 本系统无独立岗位字段，岗位靠角色权限体现，故按权限码反查（见 Inbox）。
     * 通知写入自身容错，失败只记日志、不影响审批主流程。
     */
    private function notifyPickers(SalesOrder $order): void
    {
        try {
            $userIds = Inbox::userIdsByPermissions(['delivery.pick', 'order.dispatch']);
            Inbox::sendToUsers(
                $userIds,
                "订单 {$order->order_no} 待配货",
                '订单 '.$order->order_no.'（'.($order->customer?->name ?? '').'）已审核通过，请前往配货',
                Inbox::TYPE_APPROVAL,
                'order',
                [
                    'entity_type' => 'sales_order',
                    'entity_id' => $order->id,
                    'path' => '/business/sales-order',
                ]
            );
        } catch (\Throwable $e) {
            \Log::warning('订单配货通知发送失败: '.$e->getMessage());
        }
    }

    /**
     * 会员储值/积分结算（订单流转到「已收款/待收款」时调用）。
     *
     * 成功时一定把 orders.points_settled 置 true（包括无会员/无抵扣的情况），
     * 让 approve() 的 `! points_settled` 成为干净的幂等标记，不会反复调用。
     * 失败一律抛出异常，由 approve() 外层事务统一回滚状态更新与应收款写入。
     * settleOrder 内部自带事务（嵌套事务用 savepoint），结算失败时会员侧变动自动回滚。
     */
    private function settleMemberPoints(SalesOrder $salesOrder): void
    {
        $useBalance = round((float) $salesOrder->use_balance, 2);
        $usePoints = (int) $salesOrder->use_points;

        // 无会员：仅落幂等标记，不做任何资金/积分变动
        if (! $salesOrder->member_id) {
            $salesOrder->forceFill(['points_settled' => true])->save();

            return;
        }

        $member = Member::find($salesOrder->member_id);
        if (! $member) {
            $salesOrder->forceFill(['points_settled' => true])->save();

            return;
        }

        // 结算口径用订单总额（total_amount），不用 total_amount - paid_amount：
        // paid_amount 是「已收现金」，与会员结算无关。若用它抵扣，会让 total_consume
        // 少记已收现金那部分，导致累计消费偏小、会员等级升级门槛达不到。
        $orderAmount = round(max((float) $salesOrder->total_amount, 0), 2);
        $admin = auth('admin')->user();

        $result = app(MemberService::class)->settleOrder(
            $member,
            $salesOrder->order_no,
            $orderAmount,
            $useBalance,
            $usePoints,
            $admin ? ['id' => $admin->id, 'name' => $admin->real_name ?? $admin->username ?? null] : null
        );

        $salesOrder->forceFill(['points_settled' => true])->save();

        \Log::info('会员结算成功', [
            'order_no' => $salesOrder->order_no,
            'member_id' => $member->id,
            'settle' => $result,
        ]);
    }

    /**
     * 红冲退款：把结算时扣掉的储值/积分退回，并回滚累计消费与订单数。
     *
     * 只处理 points_settled=true 的订单，避免对从未结算的单子做错误冲正。
     * 抛错向上传播，交由外层事务回滚，保证红冲与会员冲正原子性。
     */
    private function refundMemberPoints(SalesOrder $order, string $reason): void
    {
        if (! $order->member_id || ! $order->points_settled) {
            return;
        }

        $useBalance = round((float) $order->use_balance, 2);
        $usePoints = (int) $order->use_points;
        if ($useBalance <= 0 && $usePoints <= 0) {
            return;
        }

        $member = Member::find($order->member_id);
        if (! $member) {
            return;
        }

        $grossAmount = round(max((float) $order->total_amount - (float) $order->paid_amount, 0), 2);
        // 与 settleOrder 口径一致：累计消费按「实付净额」记账，红冲时也只回滚净额
        $pointsAmount = MemberService::pointsToAmount($usePoints, ['amount' => $grossAmount]);
        $netAmount = round(max($grossAmount - $useBalance - $pointsAmount, 0), 2);
        // settle 时按净实付发积分；红冲时必须一并扣回，否则会员凭空多出这些积分
        $earnPoints = MemberService::earnPointsForAmount($netAmount);

        $admin = auth('admin')->user();

        app(MemberService::class)->refundOrder(
            $member,
            $order->order_no,
            $useBalance,
            $usePoints,
            $netAmount,
            $admin ? ['id' => $admin->id, 'name' => $admin->real_name ?? $admin->username ?? null] : null,
            '，原因：'.$reason,
            $earnPoints
        );

        \Log::info('会员红冲退款', [
            'order_no' => $order->order_no,
            'member_id' => $member->id,
            'use_balance' => $useBalance,
            'use_points' => $usePoints,
        ]);
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

                // 红冲会员联动：退回已扣的储值/积分，回滚累计消费与订单数（撤单 + 改单都需要）
                $this->refundMemberPoints($order, $validated['reason']);

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
