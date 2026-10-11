<?php

namespace Modules\Business\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Customer;
use Modules\Order\Models\Supplier;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\Vehicle;
use Modules\Stock\Models\Warehouse;
use Modules\Stock\Services\StockService;

/**
 * 端到端业务流程演示种子数据
 *
 * 目的：让 QA 在全新环境里能跑通所有业务模块的「列表有数据 / 详情能看 / 流程可验证」，
 * 而不是面对一堆 0 条记录的空页。数据刻意覆盖销售→拣货→验货→装车→配送→收款→上交，
 * 以及车销、借还货、换货、进/出/调/盘点、收款/付款、退货/采购退货、往来对账、价格表。
 *
 * 幂等：全部用 updateOrInsert / firstOrCreate / where-exists 判断，可重复运行。
 * 依赖：SystemSeeder→AuthSeeder→BusinessSeeder→BusinessDataSeeder 之后运行。
 *   - BusinessDataSeeder 已建好：admin(1) 用户、CUST001/002 客户、WH001/002 仓库、
 *     P001-P005 商品、SUP001/002 供应商、京A12345/京B67890 车辆。
 */
class FlowDemoSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = (int) DB::table('auth_user')->where('username', 'admin')->value('id') ?: 1;
        $now = now();
        $today = $now->format('Y-m-d');
        $yesterday = $now->copy()->subDay()->format('Y-m-d');
        $lastWeek = $now->copy()->subWeek()->format('Y-m-d');

        // ===== 主数据引用 =====
        $wh1 = (int) Warehouse::where('code', 'WH001')->value('id');
        $wh2 = (int) Warehouse::where('code', 'WH002')->value('id');
        $custA = Customer::where('code', 'CUST001')->first();
        $custB = Customer::where('code', 'CUST002')->first();
        $supA = Supplier::where('code', 'SUP001')->first();
        $supB = Supplier::where('code', 'SUP002')->first();
        $veh1 = Vehicle::where('plate_no', '京A12345')->first();
        $veh2 = Vehicle::where('plate_no', '京B67890')->first();

        $p1 = Product::where('code', 'P001')->first(); // 矿泉水 550ml  ¥2.00 成本1
        $p2 = Product::where('code', 'P002')->first(); // 可乐 330ml   ¥3.50 成本2
        $p3 = Product::where('code', 'P003')->first(); // 巧克力饼干   ¥8.00 成本5
        $p4 = Product::where('code', 'P004')->first(); // 洗衣液 2kg   ¥25  成本15
        $products = [$p1, $p2, $p3, $p4];

        if (! $custA || ! $wh1 || ! $p1) {
            $this->command->warn('FlowDemoSeeder: 缺少前置主数据（用户/客户/仓库/商品），跳过。');

            return;
        }

        // 顺序：先建库存基础，再建各业务单据
        $stockService = app(StockService::class);
        $this->seedStockIn($wh1, $supA, $products, $adminId, $today, $now, $stockService);
        $this->seedStockOut($wh1, $custA, [$p1, $p2], $adminId, $today, $now, $stockService);
        $this->seedStockAdjust($wh1, $p1, $adminId, $today, $now, $stockService);

        $so1 = $this->seedSalesOrder($custA, $wh1, [$p1, $p3], 10, $adminId, $today, $now, 'approved');
        $so2 = $this->seedSalesOrder($custB, $wh1, [$p2, $p4], 5, $adminId, $yesterday, $now, 'pending');
        $so3 = $this->seedSalesOrder($custA, $wh1, [$p2], 20, $adminId, $lastWeek, $now, 'approved');

        $this->seedReceive($custA, $so1, $adminId, $today, $now);
        $this->seedPay($supA, $adminId, $today, $now);
        $this->seedCashFlow($custA, $so1, $today, $now, $adminId);

        $check1 = $this->seedDeliveryPickCheck($so1, $custA, $wh1, $p1, $adminId, $today, $now);
        $load1 = $this->seedDeliveryLoad($so1, $custA, $check1, $veh1, $adminId, $today, $now);
        $this->seedDeliveryTask($so1, $custA, $check1, $load1, $veh1, $adminId, $today, $now);
        $this->seedDeliveryCollection($so1, $custA, $adminId, $today, $now);
        $this->seedDeliveryRemit($custA, $adminId, $today, $now);

        $this->seedVanSale($custB, $veh2, $wh1, [$p2, $p4], $adminId, $today, $now);

        $this->seedBorrow($custA, $wh1, [$p1, $p2], $adminId, $today, $now);
        $this->seedExchange($custB, $wh1, $p2, $p4, $adminId, $today, $now);

        $this->seedSalesReturn($so1, $custA, $wh2, $p1, $adminId, $today, $now);
        $this->seedPurchaseReturn($supA, $wh2, $p3, $adminId, $today, $now);

        $this->seedCustomerReconciliation($custA, $today, $now, $adminId);
        $this->seedPriceList($custA, $p1, $adminId, $today, $now);

        $this->command->info('FlowDemoSeeder: 端到端业务流程演示数据已生成。');
    }

    // ===== 库存 =====
    // 说明：不直接写 stocks 表（MigrationSmokeTest 基线：库存只能通过业务动作产生）。
    // 库存由下方 seedStockIn / seedStockOut / seedStockAdjust 通过 StockService 真实驱动。

    // ===== 进货单（同时通过 StockService 真实入库，使库存查询页有数据） =====
    private function seedStockIn(int $whId, $supplier, array $products, int $adminId, string $date, $now, StockService $stockService): void
    {
        $no = 'RK' . str_replace('-', '', $date) . '001';
        if (DB::table('stock_ins')->where('order_no', $no)->exists()) {
            $id = (int) DB::table('stock_ins')->where('order_no', $no)->value('id');
        } else {
            $total = 0;
            $items = [];
            foreach ($products as $p) {
                $qty = 200;
                $amount = round($qty * (float) $p->cost_price, 2);
                $total += $amount;
                $items[] = [
                    'product_id' => $p->id,
                    'quantity' => $qty,
                    'price' => (float) $p->cost_price,
                    'amount' => $amount,
                    'remark' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            $id = $this->insertSafe('stock_ins', [
                'order_no' => $no,
                'type' => 'purchase',
                'warehouse_id' => $whId,
                'supplier_id' => $supplier?->id,
                'created_by' => $adminId,
                'stock_date' => $date,
                'total_amount' => round($total, 2),
                'remark' => '进货入库（演示）',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($items as $it) {
                $this->insertRow('stock_in_items', ['stock_in_id' => $id] + $it);
            }
            // 真实驱动库存：通过业务动作入库（StockService 同时写 stocks 与 stocks_history）
            foreach ($products as $p) {
                $stockService->stockIn((int) $p->id, $whId, 200, (float) $p->cost_price, $id, 'StockIn');
            }
        }
    }

    // ===== 出库单 =====
    private function seedStockOut(int $whId, $customer, array $products, int $adminId, string $date, $now, StockService $stockService): void
    {
        $no = 'CK' . str_replace('-', '', $date) . '001';
        if (! DB::table('stock_outs')->where('order_no', $no)->exists()) {
            $total = 0;
            $id = $this->insertSafe('stock_outs', [
                'order_no' => $no,
                'type' => 'sales',
                'warehouse_id' => $whId,
                'customer_id' => $customer?->id,
                'created_by' => $adminId,
                'stock_date' => $date,
                'total_amount' => 0,
                'remark' => '销售出库（演示）',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($products as $p) {
                $qty = 50;
                $amount = round($qty * (float) $p->price_small, 2);
                $total += $amount;
                $this->insertRow('stock_out_items', [
                    'stock_out_id' => $id,
                    'product_id' => $p->id,
                    'quantity' => $qty,
                    'price' => (float) $p->price_small,
                    'amount' => $amount,
                    'remark' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            DB::table('stock_outs')->where('id', $id)->update(['total_amount' => round($total, 2)]);
            // 真实驱动库存：通过业务动作出库
            foreach ($products as $p) {
                $stockService->stockOut((int) $p->id, $whId, 50, $id, 'StockOut', '销售出库（演示）');
            }
        }
    }

    // ===== 库存调整（报溢，已审核） =====
    private function seedStockAdjust(int $whId, $product, int $adminId, string $date, $now, StockService $stockService): void
    {
        $no = 'KT' . str_replace('-', '', $date) . '001';
        if (DB::table('stock_adjusts')->where('adjust_no', $no)->exists()) {
            return;
        }
        $qty = 5;
        $cost = (float) $product->cost_price;
        $id = $this->insertSafe('stock_adjusts', [
            'adjust_no' => $no,
            'warehouse_id' => $whId,
            'adjust_date' => $date,
            'adjust_type' => 'surplus',
            'status' => 'approved',
            'total_qty' => $qty,
            'total_amount' => round($qty * $cost, 2),
            'reason' => '盘点报溢（演示）',
            'created_by' => $adminId,
            'creator_name' => '超级管理员',
            'approved_by' => $adminId,
            'approver_name' => '超级管理员',
            'approved_at' => $now,
            'approval_comment' => '同意',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insertRow('stock_adjust_items', [
            'adjust_id' => $id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'spec' => $product->spec,
            'unit' => $product->price_unit_small,
            'before_qty' => $product->stock_qty ?? 1000,
            'adjust_qty' => $qty,
            'after_qty' => ($product->stock_qty ?? 1000) + $qty,
            'unit_cost' => $cost,
            'total_cost' => round($qty * $cost, 2),
            'remark' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        // 真实驱动库存：报溢 +5（adjust 正数即增加）
        $stockService->adjust((int) $product->id, $whId, $qty, $cost, $id, 'surplus', '盘点报溢（演示）', 'StockAdjust');
    }

    // ===== 销售订单 =====
    private function seedSalesOrder($customer, int $whId, array $products, int $qty, int $adminId, string $date, $now, string $status): int
    {
        $no = 'SO' . str_replace('-', '', $date) . str_pad((string) $customer->id, 4, '0', STR_PAD_LEFT);
        if (DB::table('sales_orders')->where('order_no', $no)->exists()) {
            return (int) DB::table('sales_orders')->where('order_no', $no)->value('id');
        }
        $totalQty = 0;
        $totalAmount = 0;
        $items = [];
        foreach ($products as $p) {
            $amount = round($qty * (float) $p->price_small, 2);
            $totalQty += $qty;
            $totalAmount += $amount;
            $items[] = [
                'product_id' => $p->id,
                'quantity' => $qty,
                'actual_qty' => $qty,
                'qty_large' => 0,
                'qty_medium' => 0,
                'qty_small' => $qty,
                'price' => (float) $p->price_small,
                'price_large' => (float) $p->price_large,
                'price_medium' => (float) $p->price_small,
                'price_small' => (float) $p->price_small,
                'amount' => $amount,
                'remark' => null,
                'sale_mode' => 'normal',
                'tax_rate' => 13,
                'discount_rate' => 100,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $id = $this->insertSafe('sales_orders', [
            'order_no' => $no,
            // 生产库 order_type 是 enum('wholesale','retail')，dev 是 varchar；
            // 取 'wholesale' 兼容两种 schema。
            'order_type' => 'wholesale',
            'customer_id' => $customer->id,
            'warehouse_id' => $whId,
            'order_date' => $date,
            'total_amount' => round($totalAmount, 2),
            'total_qty' => $totalQty,
            'paid_amount' => $status === 'approved' ? round($totalAmount, 2) : 0,
            'status' => $status,
            // 生产库 transferred_to 是 NOT NULL DEFAULT 0，不能传 null。
            'transferred_to' => 0,
            'salesman_id' => $adminId,
            'salesman_name' => '超级管理员',
            'created_by' => $adminId,
            'approved_by' => $status === 'approved' ? $adminId : null,
            'approved_at' => $status === 'approved' ? $now : null,
            'remark' => '销售订单（演示）',
            'print_count' => 0,
            'payment_status' => $status === 'approved' ? 'paid' : 'unpaid',
            'discount_amount' => 0,
            'delivery_date' => $date,
            'print_type' => 'a4',
            'sort_type' => 'default',
            'source' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($items as $it) {
            $this->insertRow('sales_order_items', ['sales_order_id' => $id] + $it);
        }

        return $id;
    }

    // ===== 收款单 =====
    private function seedReceive($customer, int $soId, int $adminId, string $date, $now): void
    {
        $no = 'SK' . str_replace('-', '', $date) . '001';
        if (DB::table('receives')->where('receive_no', $no)->exists()) {
            return;
        }
        $amount = (float) DB::table('sales_orders')->where('id', $soId)->value('total_amount');
        $id = $this->insertSafe('receives', [
            'receive_no' => $no,
            'receive_type' => 1,
            'customer_id' => $customer->id,
            'sales_order_id' => $soId,
            'amount' => $amount,
            'receive_date' => $date,
            'payment_method' => 'cash',
            'handler_id' => $adminId,
            'remark' => '收款（演示）',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insertRow('receive_items', [
            'receive_id' => $id,
            'sales_order_id' => $soId,
            'order_no' => (string) DB::table('sales_orders')->where('id', $soId)->value('order_no'),
            'pay_amount' => $amount,
            'paid_before' => 0,
            'receivable_before' => $amount,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // ===== 付款单 =====
    private function seedPay($supplier, int $adminId, string $date, $now): void
    {
        $no = 'FK' . str_replace('-', '', $date) . '001';
        if (DB::table('pays')->where('pay_no', $no)->exists()) {
            return;
        }
        $this->insertRow('pays', [
            'pay_no' => $no,
            'pay_type' => 1,
            'supplier_id' => $supplier?->id,
            'purchase_order_id' => null,
            'amount' => 1000,
            'pay_date' => $date,
            'payment_method' => 'bank',
            'handler_id' => $adminId,
            'remark' => '供应商付款（演示）',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // ===== 现金流水 =====
    private function seedCashFlow($customer, int $soId, string $date, $now, int $adminId): void
    {
        $no = 'CF' . str_replace('-', '', $date) . '001';
        if (DB::table('cash_flows')->where('flow_no', $no)->exists()) {
            return;
        }
        $amount = (float) DB::table('sales_orders')->where('id', $soId)->value('total_amount');
        $this->insertRow('cash_flows', [
            'flow_no' => $no,
            'flow_type' => 'receive',
            'customer_id' => $customer->id,
            'related_id' => $soId,
            'related_type' => 'SalesOrder',
            'flow_date' => $date,
            'amount' => $amount,
            'payment_method' => 'cash',
            'remark' => '销售收款（演示）',
            'created_by' => $adminId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // ===== 配送：拣选单（上游，拣货单之源） =====
    private function seedDeliveryPicking(int $soId, $customer, int $whId, $product, int $qty, int $adminId, string $date, $now): array
    {
        $orderNo = (string) DB::table('sales_orders')->where('id', $soId)->value('order_no');
        $pickingNo = 'PICK' . str_replace('-', '', $date) . str_pad((string) $soId, 4, '0', STR_PAD_LEFT);

        $pickingId = DB::table('delivery_picking')->where('picking_no', $pickingNo)->exists()
            ? (int) DB::table('delivery_picking')->where('picking_no', $pickingNo)->value('id')
            : $this->insertSafe('delivery_picking', [
                'picking_no' => $pickingNo,
                'sales_order_id' => $soId,
                'order_no' => $orderNo,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'warehouse_id' => $whId,
                'picking_date' => $date,
                'total_skus' => 1,
                'total_qty' => $qty,
                'total_amount' => round($qty * (float) $product->price_small, 2),
                'status' => 'picked',
                'stock_frozen' => 0,
                'frozen_from_order' => 0,
                'pick_id' => null,
                'created_by' => $adminId,
                'creator_name' => '超级管理员',
                'confirmed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        if (! DB::table('delivery_picking_items')->where('picking_id', $pickingId)->exists()) {
            $this->insertRow('delivery_picking_items', [
                'picking_id' => $pickingId,
                'sales_order_item_id' => null,
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'spec' => $product->spec,
                'unit' => $product->price_unit_small,
                'order_qty' => $qty,
                'qty_large' => 0,
                'qty_medium' => 0,
                'qty_small' => $qty,
                'quantity' => $qty,
                'price' => (float) $product->price_small,
                'amount' => round($qty * (float) $product->price_small, 2),
                'stock_qty' => $product->stock_qty ?? 1000,
                'remark' => null,
                'sort' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return [$pickingId, $pickingNo];
    }

    // ===== 配送：拣货 + 验货（一条龙） =====
    private function seedDeliveryPickCheck(int $soId, $customer, int $whId, $product, int $adminId, string $date, $now): int
    {
        $orderNo = (string) DB::table('sales_orders')->where('id', $soId)->value('order_no');
        [$pickingId, $pickingNo] = $this->seedDeliveryPicking($soId, $customer, $whId, $product, 10, $adminId, $date, $now);
        $pickNo = 'PJ' . str_replace('-', '', $date) . str_pad((string) $soId, 4, '0', STR_PAD_LEFT);
        $checkNo = 'YH' . str_replace('-', '', $date) . str_pad((string) $soId, 4, '0', STR_PAD_LEFT);

        $pickId = DB::table('delivery_pick')->where('pick_no', $pickNo)->exists()
            ? (int) DB::table('delivery_pick')->where('pick_no', $pickNo)->value('id')
            : $this->insertSafe('delivery_pick', [
                'pick_no' => $pickNo,
                'picking_id' => $pickingId,
                'picking_no' => $pickingNo,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'warehouse_id' => $whId,
                'pick_date' => $date,
                'picker_id' => $adminId,
                'picker_name' => '超级管理员',
                'total_skus' => 1,
                'total_qty' => 10,
                'short_qty' => 0,
                'status' => 'checked',
                'check_id' => null,
                'remark' => '拣货（演示）',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        if (! DB::table('delivery_pick_items')->where('pick_id', $pickId)->exists()) {
            $this->insertRow('delivery_pick_items', [
                'pick_id' => $pickId,
                'picking_item_id' => null,
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'spec' => $product->spec,
                'unit' => $product->price_unit_small,
                'pick_qty' => 10,
                'actual_qty' => 10,
                'short_qty' => 0,
                'bin_location' => 'A-01',
                'remark' => null,
                'sort' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $checkId = DB::table('delivery_check')->where('check_no', $checkNo)->exists()
            ? (int) DB::table('delivery_check')->where('check_no', $checkNo)->value('id')
            : $this->insertSafe('delivery_check', [
                'check_no' => $checkNo,
                'pick_id' => $pickId,
                'pick_no' => $pickNo,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'check_date' => $date,
                'checker_id' => $adminId,
                'checker_name' => '超级管理员',
                'total_skus' => 1,
                'expected_qty' => 10,
                'actual_qty' => 10,
                'diff_qty' => 0,
                'status' => 'checked',
                'load_id' => null,
                'remark' => '验货通过（演示）',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        if (! DB::table('delivery_check_items')->where('check_id', $checkId)->exists()) {
            $this->insertRow('delivery_check_items', [
                'check_id' => $checkId,
                'pick_item_id' => null,
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'spec' => $product->spec,
                'unit' => $product->price_unit_small,
                'pick_qty' => 10,
                'actual_qty' => 10,
                'diff_qty' => 0,
                'remark' => null,
                'sort' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('delivery_pick')->where('id', $pickId)->update(['check_id' => $checkId]);

        return $checkId;
    }

    // ===== 装车单 =====
    private function seedDeliveryLoad(int $soId, $customer, int $checkId, $vehicle, int $adminId, string $date, $now): int
    {
        $orderNo = (string) DB::table('sales_orders')->where('id', $soId)->value('order_no');
        $checkNo = (string) DB::table('delivery_check')->where('id', $checkId)->value('check_no');
        $loadNo = 'ZC' . str_replace('-', '', $date) . str_pad((string) $soId, 4, '0', STR_PAD_LEFT);

        if (DB::table('delivery_load')->where('load_no', $loadNo)->exists()) {
            return (int) DB::table('delivery_load')->where('load_no', $loadNo)->value('id');
        }
        $product = (function () use ($soId) {
            $it = DB::table('sales_order_items')->where('sales_order_id', $soId)->first();

            return $it ? DB::table('products')->where('id', $it->product_id)->first() : null;
        })();
        $loadId = $this->insertSafe('delivery_load', [
            'load_no' => $loadNo,
            'load_date' => $date,
            'delivery_person_id' => $adminId,
            'delivery_person_name' => '超级管理员',
            'employee_id' => null,
            'vehicle_id' => $vehicle?->id,
            'plate_no' => $vehicle?->plate_no,
            'order_count' => 1,
            'total_skus' => 1,
            'total_qty' => 10,
            'total_amount' => (float) DB::table('sales_orders')->where('id', $soId)->value('total_amount'),
            'status' => 'loaded',
            'remark' => '装车（演示）',
            'created_by' => $adminId,
            'creator_name' => '超级管理员',
            'loaded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insertRow('delivery_load_items', [
            'load_id' => $loadId,
            'check_id' => $checkId,
            'check_no' => $checkNo,
            'sales_order_id' => $soId,
            'order_no' => $orderNo,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'address' => $customer?->address ?: '客户地址',
            'phone' => $customer?->phone ?: '',
            'product_id' => $product?->id,
            'product_code' => $product?->code,
            'product_name' => $product?->name,
            'spec' => $product?->spec,
            'unit' => $product?->price_unit_small,
            'quantity' => 10,
            'price' => (float) $product?->price_small,
            'amount' => round(10 * (float) $product?->price_small, 2),
            'picking_id' => null,
            'remark' => null,
            'sort' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('delivery_check')->where('id', $checkId)->update(['load_id' => $loadId]);

        return $loadId;
    }

    // ===== 配送任务 =====
    private function seedDeliveryTask(int $soId, $customer, int $checkId, int $loadId, $vehicle, int $adminId, string $date, $now): void
    {
        $taskNo = 'PS' . str_replace('-', '', $date) . str_pad((string) $soId, 4, '0', STR_PAD_LEFT);
        if (DB::table('delivery_task')->where('task_no', $taskNo)->exists()) {
            return;
        }
        $so = DB::table('sales_orders')->where('id', $soId)->first();
        $loadNo = (string) DB::table('delivery_load')->where('id', $loadId)->value('load_no');
        $checkNo = (string) DB::table('delivery_check')->where('id', $checkId)->value('check_no');
        $this->insertRow('delivery_task', [
            'task_no' => $taskNo,
            'load_id' => $loadId,
            'load_no' => $loadNo,
            'check_id' => $checkId,
            'check_no' => $checkNo,
            'sales_order_id' => $soId,
            'order_no' => $so->order_no,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'address' => $customer?->address ?: '客户地址',
            'phone' => $customer?->phone ?: '',
            'warehouse_id' => $so->warehouse_id,
            'delivery_person_id' => $adminId,
            'delivery_person_name' => '超级管理员',
            'employee_id' => null,
            'vehicle_id' => $vehicle?->id,
            'plate_no' => $vehicle?->plate_no,
            'total_skus' => 1,
            'total_qty' => 10,
            'order_amount' => (float) $so->total_amount,
            'paid_amount' => (float) $so->paid_amount,
            'unpaid_amount' => round((float) $so->total_amount - (float) $so->paid_amount, 2),
            'status' => 'delivered',
            'exception_type' => null,
            'exception_remark' => null,
            'started_at' => $now,
            'delivered_at' => $now,
            'collected_at' => $now,
            'remark' => '配送任务（演示）',
            'created_at' => $now,
            'updated_at' => $now,
            'cancel_reason' => null,
            'return_id' => null,
        ]);
    }

    // ===== 配送收款 =====
    private function seedDeliveryCollection(int $soId, $customer, int $adminId, string $date, $now): void
    {
        $taskNo = 'PS' . str_replace('-', '', $date) . str_pad((string) $soId, 4, '0', STR_PAD_LEFT);
        $taskId = (int) DB::table('delivery_task')->where('task_no', $taskNo)->value('id');
        $so = DB::table('sales_orders')->where('id', $soId)->first();
        $colNo = 'SK2' . str_replace('-', '', $date) . str_pad((string) $soId, 4, '0', STR_PAD_LEFT);
        if (DB::table('delivery_collection')->where('collection_no', $colNo)->exists()) {
            return;
        }
        $this->insertRow('delivery_collection', [
            'collection_no' => $colNo,
            'task_id' => $taskId,
            'task_no' => $taskNo,
            'sales_order_id' => $soId,
            'order_no' => $so->order_no,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'delivery_person_id' => $adminId,
            'delivery_person_name' => '超级管理员',
            'employee_id' => null,
            'receivable_amount' => (float) $so->total_amount,
            'received_amount' => (float) $so->total_amount,
            'payment_method' => 'cash',
            'collect_date' => $date,
            'status' => 'confirmed',
            'remark' => '配送收款（演示）',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // ===== 上交货款 =====
    private function seedDeliveryRemit($customer, int $adminId, string $date, $now): void
    {
        $remitNo = 'SJ' . str_replace('-', '', $date) . '001';
        if (DB::table('delivery_remit')->where('remit_no', $remitNo)->exists()) {
            return;
        }
        $amount = (float) DB::table('delivery_collection')->where('collect_date', $date)->sum('received_amount');
        $remitId = $this->insertSafe('delivery_remit', [
            'remit_no' => $remitNo,
            'remit_date' => $date,
            'delivery_person_id' => $adminId,
            'delivery_person_name' => '超级管理员',
            'employee_id' => null,
            'cash_amount' => $amount,
            'wechat_amount' => 0,
            'alipay_amount' => 0,
            'bank_amount' => 0,
            'total_amount' => $amount,
            'status' => 'confirmed',
            'remark' => '上交货款（演示）',
            'confirmed_by' => $adminId,
            'confirmed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach (DB::table('delivery_collection')->where('collect_date', $date)->get() as $col) {
            $this->insertRow('delivery_remit_items', [
                'remit_id' => $remitId,
                'collection_id' => $col->id,
                'collection_no' => $col->collection_no,
                'payment_method' => $col->payment_method,
                'amount' => (float) $col->received_amount,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    // ===== 车销单 =====
    private function seedVanSale($customer, $vehicle, int $whId, array $products, int $adminId, string $date, $now): void
    {
        $no = 'CX' . str_replace('-', '', $date) . '001';
        if (DB::table('van_sale_orders')->where('order_no', $no)->exists()) {
            return;
        }
        $totalQty = 0;
        $totalAmount = 0;
        $items = [];
        foreach ($products as $p) {
            $qty = 8;
            $amount = round($qty * (float) $p->price_small, 2);
            $totalQty += $qty;
            $totalAmount += $amount;
            $items[] = [
                'product_id' => $p->id,
                'product_code' => $p->code,
                'product_name' => $p->name,
                'spec' => $p->spec,
                'unit' => $p->price_unit_small,
                'stock_qty' => $qty,
                'sale_qty' => $qty,
                'unit_price' => (float) $p->price_small,
                'price_source' => 'default',
                'amount' => $amount,
                'remark' => null,
                'sort' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $id = $this->insertSafe('van_sale_orders', [
            'order_no' => $no,
            'salesman_id' => $adminId,
            'salesman_name' => '超级管理员',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'vehicle_id' => $vehicle?->id,
            'vehicle_warehouse_id' => $whId,
            'sale_date' => $date,
            'total_qty' => $totalQty,
            'total_amount' => round($totalAmount, 2),
            'paid_amount' => round($totalAmount, 2),
            'payment_method' => 'cash',
            'status' => 'approved',
            'visit_log_id' => null,
            'created_by' => $adminId,
            'creator_name' => '超级管理员',
            'approved_at' => $now,
            'remark' => '车销（演示）',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($items as $it) {
            $this->insertRow('van_sale_order_items', ['order_id' => $id] + $it);
        }
    }

    // ===== 借货单 + 借还货单 =====
    private function seedBorrow($customer, int $whId, array $products, int $adminId, string $date, $now): void
    {
        $borrowNo = 'JH' . str_replace('-', '', $date) . '001';
        if (DB::table('borrow_orders')->where('borrow_no', $borrowNo)->exists()) {
            return;
        }
        $totalQty = 0;
        $totalAmount = 0;
        $items = [];
        foreach ($products as $p) {
            $qty = 6;
            $amount = round($qty * (float) $p->price_small, 2);
            $totalQty += $qty;
            $totalAmount += $amount;
            $items[] = [
                'product_id' => $p->id,
                'product_code' => $p->code,
                'product_name' => $p->name,
                'spec' => $p->spec,
                'unit' => $p->price_unit_small,
                'borrow_qty' => $qty,
                'returned_qty' => 0,
                'unit_price' => (float) $p->price_small,
                'amount' => $amount,
                'remark' => null,
                'sort' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $borrowId = $this->insertSafe('borrow_orders', [
            'borrow_no' => $borrowNo,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'contact' => $customer->contact,
            'contact_phone' => $customer->phone,
            'warehouse_id' => $whId,
            'warehouse_name' => '主仓库',
            'salesman_id' => $adminId,
            'salesman_name' => '超级管理员',
            'borrow_date' => $date,
            'due_date' => date('Y-m-d', strtotime($date . ' +7 days')),
            'borrow_reason' => '活动借货（演示）',
            'total_kinds' => count($products),
            'total_qty' => $totalQty,
            'total_amount' => round($totalAmount, 2),
            'returned_qty' => 0,
            'returned_amount' => 0,
            'status' => 'approved',
            'created_by' => $adminId,
            'creator_name' => '超级管理员',
            'remark' => null,
            'approved_at' => $now,
            'cancelled_at' => null,
            'cancel_reason' => null,
            'converted_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($items as $it) {
            $this->insertRow('borrow_order_items', ['order_id' => $borrowId] + $it);
        }

        // 借还货单：归还一半
        $returnNo = 'JH' . str_replace('-', '', $date) . 'R01';
        if (DB::table('borrow_return_orders')->where('return_no', $returnNo)->exists()) {
            return;
        }
        $retId = $this->insertSafe('borrow_return_orders', [
            'return_no' => $returnNo,
            'borrow_order_id' => $borrowId,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $whId,
            'warehouse_name' => '主仓库',
            'salesman_id' => $adminId,
            'salesman_name' => '超级管理员',
            'return_date' => $date,
            'return_reason' => '归还部分借货（演示）',
            'total_kinds' => count($products),
            'total_qty' => (int) ceil($totalQty / 2),
            'total_amount' => round($totalAmount / 2, 2),
            'good_qty' => (int) ceil($totalQty / 2),
            'bad_qty' => 0,
            'status' => 'approved',
            'created_by' => $adminId,
            'creator_name' => '超级管理员',
            'remark' => null,
            'approved_at' => $now,
            'cancelled_at' => null,
            'cancel_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($items as $it) {
            $this->insertRow('borrow_return_order_items', [
                'order_id' => $retId,
                'borrow_order_item_id' => null,
                'product_id' => $it['product_id'],
                'product_code' => $it['product_code'],
                'product_name' => $it['product_name'],
                'spec' => $it['spec'],
                'unit' => $it['unit'],
                'unreturned_qty' => $it['borrow_qty'],
                'return_qty' => (int) ceil($it['borrow_qty'] / 2),
                'good_qty' => (int) ceil($it['borrow_qty'] / 2),
                'bad_qty' => 0,
                'bad_reason' => null,
                'unit_price' => $it['unit_price'],
                'amount' => round($it['amount'] / 2, 2),
                'remark' => null,
                'sort' => $it['sort'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    // ===== 换货单 =====
    private function seedExchange($customer, int $whId, $productOut, $productIn, int $adminId, string $date, $now): void
    {
        $no = 'HH' . str_replace('-', '', $date) . '001';
        if (DB::table('exchange_orders')->where('exchange_no', $no)->exists()) {
            return;
        }
        $qty = 5;
        $amountOut = round($qty * (float) $productOut->price_small, 2);
        $amountIn = round($qty * (float) $productIn->price_small, 2);
        $id = $this->insertSafe('exchange_orders', [
            'exchange_no' => $no,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $whId,
            'warehouse_name' => '主仓库',
            'salesman_id' => $adminId,
            'salesman_name' => '超级管理员',
            'exchange_date' => $date,
            'exchange_reason' => '质量换货（演示）',
            'total_kinds_out' => 1,
            'total_kinds_in' => 1,
            'total_qty_out' => $qty,
            'total_qty_in' => $qty,
            'amount_out' => $amountOut,
            'amount_in' => $amountIn,
            'diff_amount' => round($amountIn - $amountOut, 2),
            'payment_method' => 'cash',
            'refund_method' => 'cash',
            'status' => 'approved',
            'created_by' => $adminId,
            'creator_name' => '超级管理员',
            'remark' => null,
            'approved_at' => $now,
            'rejected_at' => null,
            'reject_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insertRow('exchange_order_items', [
            'order_id' => $id,
            'product_id_out' => $productOut->id,
            'product_name_out' => $productOut->name,
            'spec_out' => $productOut->spec,
            'unit_out' => $productOut->price_unit_small,
            'qty' => $qty,
            'unit_price_out' => (float) $productOut->price_small,
            'amount_out' => $amountOut,
            'product_id_in' => $productIn->id,
            'product_name_in' => $productIn->name,
            'spec_in' => $productIn->spec,
            'unit_in' => $productIn->price_unit_small,
            'unit_price_in' => (float) $productIn->price_small,
            'amount_in' => $amountIn,
            'diff_amount' => round($amountIn - $amountOut, 2),
            'remark' => null,
            'sort' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // ===== 销售退货 =====
    private function seedSalesReturn(int $soId, $customer, int $whId, $product, int $adminId, string $date, $now): void
    {
        $no = 'XT' . str_replace('-', '', $date) . '001';
        if (DB::table('sales_returns')->where('return_no', $no)->exists()) {
            return;
        }
        $so = DB::table('sales_orders')->where('id', $soId)->first();
        $qty = 2;
        $price = (float) $product->price_small;
        $id = $this->insertSafe('sales_returns', [
            'return_no' => $no,
            'order_id' => $soId,
            'order_no' => $so->order_no,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $whId,
            'return_date' => $date,
            'return_type' => 'quality',
            'status' => 'approved',
            'total_skus' => 1,
            'total_qty' => $qty,
            'total_amount' => round($qty * $price, 2),
            'refund_amount' => round($qty * $price, 2),
            'receivable_offset' => round($qty * $price, 2),
            'remark' => '质量退货（演示）',
            'created_by' => $adminId,
            'approved_by' => $adminId,
            'approved_at' => $now,
            'approval_comment' => '同意退货',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insertRow('sales_return_items', [
            'return_id' => $id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'spec' => $product->spec,
            'unit' => $product->price_unit_small,
            'order_qty' => 10,
            'returned_qty' => $qty,
            'return_qty' => $qty,
            'return_price' => $price,
            'return_amount' => round($qty * $price, 2),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // ===== 采购退货 =====
    private function seedPurchaseReturn($supplier, int $whId, $product, int $adminId, string $date, $now): void
    {
        $no = 'CT' . str_replace('-', '', $date) . '001';
        if (DB::table('purchase_returns')->where('return_no', $no)->exists()) {
            return;
        }
        $qty = 5;
        $price = (float) $product->cost_price;
        $id = $this->insertSafe('purchase_returns', [
            'return_no' => $no,
            'stock_in_id' => null,
            'stock_in_no' => null,
            'supplier_id' => $supplier?->id,
            'supplier_name' => $supplier?->name,
            'warehouse_id' => $whId,
            'return_date' => $date,
            'status' => 'approved',
            'total_skus' => 1,
            'total_qty' => $qty,
            'total_amount' => round($qty * $price, 2),
            'payable_offset' => round($qty * $price, 2),
            'contact' => $supplier?->contact,
            'phone' => $supplier?->phone,
            'remark' => '采购退货（演示）',
            'created_by' => $adminId,
            'approved_by' => $adminId,
            'approved_at' => $now,
            'approval_comment' => '同意',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insertRow('purchase_return_items', [
            'return_id' => $id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'spec' => $product->spec,
            'unit' => $product->price_unit_small,
            'original_qty' => 50,
            'returned_qty' => $qty,
            'return_qty' => $qty,
            'return_price' => $price,
            'return_amount' => round($qty * $price, 2),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // ===== 往来对账 =====
    private function seedCustomerReconciliation($customer, string $date, $now, int $adminId): void
    {
        // 往来对账通常是报表视图（应收/应付汇总），无独立表——若存在 customer_reconciliations 表则写入，
        // 否则由 sales_orders + receives + cash_flows 的数据自然呈现。insertRow 自带缺表/缺列跳过。
        if (! \Illuminate\Support\Facades\Schema::hasTable('customer_reconciliations')) {
            return;
        }
        $no = 'DZ' . str_replace('-', '', $date) . '001';
        if (DB::table('customer_reconciliations')->where('reconciliation_no', $no)->exists()) {
            return;
        }
        $this->insertRow('customer_reconciliations', [
            'reconciliation_no' => $no,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'reconciliation_date' => $date,
            'receivable_amount' => (float) DB::table('sales_orders')->where('customer_id', $customer->id)->sum('total_amount'),
            'received_amount' => (float) DB::table('receives')->where('customer_id', $customer->id)->sum('amount'),
            'balance_amount' => 0,
            'status' => 'confirmed',
            'remark' => '往来对账（演示）',
            'created_by' => $adminId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // ===== 价格表 =====
    private function seedPriceList($customer, $product, int $adminId, string $date, $now): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('price_lists')) {
            return;
        }
        $no = 'JG' . str_replace('-', '', $date) . '001';
        if (DB::table('price_lists')->where('price_no', $no)->exists()) {
            return;
        }
        $id = $this->insertSafe('price_lists', [
            'price_no' => $no,
            'price_date' => $date,
            'customer_id' => $customer?->id,
            'customer_name' => $customer?->name,
            'status' => 'active',
            'remark' => '价格表（演示）',
            'created_by' => $adminId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($id > 0) {
            $this->insertRow('price_list_items', [
                'price_list_id' => $id,
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'spec' => $product->spec,
                'unit' => $product->price_unit_small,
                'price' => (float) $product->price_small,
                'remark' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * 生产库 schema 可能落后于 dev（历史演进），直接 insert 会因缺列/缺表报错。
     * cols() 过滤两类隐患：
     *   1) 目标表不存在的列 —— 自动剔除
     *   2) NOT NULL 且无默认值的列却传了 null —— 剔除该列，让 DB 用默认值，
     *      避免 'Integrity constraint violation: NOT NULL'（如生产库
     *      transferred_to NOT NULL DEFAULT 0）。有默认值的列同理省略更安全。
     * insertSafe/insertRow 缺表则整段跳过。这让 seeder 对任何 schema 版本健壮。
     */
    private function cols(string $table, array $row): array
    {
        static $cache = [];
        if (! isset($cache[$table])) {
            $cache[$table] = \Illuminate\Support\Facades\Schema::hasTable($table)
                ? \Illuminate\Support\Facades\Schema::getColumns($table)
                : [];
        }
        if (empty($cache[$table])) {
            return [];
        }
        $byName = [];
        foreach ($cache[$table] as $c) {
            $byName[$c['name']] = $c;
        }

        $out = [];
        foreach ($row as $k => $v) {
            if (! isset($byName[$k])) {
                continue; // 列不存在，剔除
            }
            $col = $byName[$k];
            // 传 null 给 NOT NULL（无默认）列会报约束错；省略让 DB 兜底默认值
            if ($v === null && ! ($col['nullable'] ?? true) && ($col['default'] === null)) {
                continue;
            }
            $out[$k] = $v;
        }

        return $out;
    }

    /** 插入并返回 id；目标表不存在或过滤后无列则返回 0（调用方应跳过后续） */
    private function insertSafe(string $table, array $row): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            return 0;
        }
        $row = $this->cols($table, $row);
        if (empty($row)) {
            return 0;
        }

        return (int) DB::table($table)->insertGetId($row);
    }

    /** 无返回值的插入（用于明细行）；目标表/列缺失则静默跳过 */
    private function insertRow(string $table, array $row): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            return;
        }
        $row = $this->cols($table, $row);
        if (empty($row)) {
            return;
        }
        DB::table($table)->insert($row);
    }
}
