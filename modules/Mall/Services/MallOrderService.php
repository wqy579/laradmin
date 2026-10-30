<?php

namespace Modules\Mall\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User as AdminUser;
use Modules\Mall\Models\MallOrder;
use Modules\Mall\Models\MallOrderItem;
use Modules\Order\Models\SalesOrder;
use Modules\Order\Models\SalesOrderItem;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\Stock;
use Modules\Stock\Services\StockService;

/**
 * F5 分销商城 → F1 订货申报单 桥。
 *
 * 验收硬性要求：商城订单必须走 F1 通道生成申报单（sales_orders + sales_order_items）。
 * 本服务复用 Order 域的模型与 StockService 冻结逻辑，不改写 A 机/Order 域代码——
 * 只在商城下单时，以与 SalesOrderController::store 相同的口径创建申报单，
 * 并把申报单 ID/单号回写 mall_orders.sales_order_id / sales_order_no。
 *
 * 单一数据源：申报单金额/数量由本服务按三档单价重算（不信前端 amount），
 * 与 F1 的 itemAmount / itemQuantity 算法一致。
 */
class MallOrderService
{
    public function __construct(private StockService $stocks) {}

    /**
     * 下单：创建商城订单 → 生成申报单 → 回写关联。
     *
     * @param  array  $items  [{product_id, qty_large, qty_medium, qty_small}]
     *
     * @throws \DomainException 校验失败（客户/仓库缺失、明细为空、库存不足、零价）
     */
    public function placeOrder(array $payload, int $operatorId): MallOrder
    {
        // 幂等键（可选）：同一键重复提交返回已建订单，不产生第二条申报单。
        $idempotencyKey = $payload['idempotency_key'] ?? null;
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = MallOrder::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->load('items');
            }
        }

        $items = $payload['items'] ?? [];
        if ($items === [] || count($items) > 200) {
            throw new \DomainException('请选择至少一个商品（单笔最多 200 行）');
        }

        $customerId = (int) ($payload['customer_id'] ?? 0);
        $warehouseId = (int) ($payload['warehouse_id'] ?? 0);
        if ($customerId <= 0 || $warehouseId <= 0) {
            throw new \DomainException('请选择客户与提货仓库');
        }

        $customer = DB::table('customers')->where('id', $customerId)->first();
        if (! $customer) {
            throw new \DomainException('客户不存在');
        }
        $warehouse = DB::table('warehouses')->where('id', $warehouseId)->first();
        if (! $warehouse) {
            throw new \DomainException('仓库不存在');
        }

        // 明细预处理：读 products 单一数据源 + 校验库存
        $rows = $this->normalizeItems($items, $warehouseId);
        $operator = AdminUser::find($operatorId);
        $operatorName = $operator?->real_name ?: $operator?->username ?: '商城下单';

        DB::beginTransaction();
        try {
            // 1) 商城订单
            $mallOrder = MallOrder::create([
                'order_no' => $this->nextMallOrderNo(),
                'idempotency_key' => $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : null,
                'customer_id' => $customerId,
                'customer_name' => $customer->name,
                'warehouse_id' => $warehouseId,
                'order_date' => $payload['order_date'] ?? now()->toDateString(),
                'total_amount' => $rows['total_amount'],
                'total_qty' => $rows['total_qty'],
                'paid_amount' => 0,
                'status' => 'pending',
                'receiver_name' => $payload['receiver_name'] ?? ($customer->name ?? null),
                'receiver_phone' => $payload['receiver_phone'] ?? ($customer->phone ?? null),
                'receiver_address' => $payload['receiver_address'] ?? ($customer->address ?? null),
                'created_by' => $operatorId,
                'creator_name' => $operatorName,
            ]);

            // 1.5) 商城明细快照
            foreach ($rows['rows'] as $row) {
                MallOrderItem::create([
                    'order_id' => $mallOrder->id,
                    'product_id' => $row['product_id'],
                    'product_code' => $row['product']->code,
                    'product_name' => $row['product']->name,
                    'spec' => $row['product']->spec,
                    'unit_large' => $row['product']->price_unit,
                    'unit_medium' => $row['product']->barcode_medium_unit,
                    'unit_small' => $row['product']->price_unit_small,
                    'unit_conversion' => $row['product']->unit_conversion,
                    'unit_conversion_medium' => $row['product']->unit_conversion_medium,
                    'qty_large' => $row['qty_large'],
                    'qty_medium' => $row['qty_medium'],
                    'qty_small' => $row['qty_small'],
                    'price_large' => $row['price_large'],
                    'price_medium' => $row['price_medium'],
                    'price_small' => $row['price_small'],
                    'quantity' => $row['quantity'],
                    'amount' => $row['amount'],
                ]);
            }

            // 2) 申报单（F1 通道）
            $salesOrder = $this->createDeclaration($mallOrder, $rows, $customerId, $warehouseId, $operatorId, $operatorName);

            // 3) 回写关联
            $mallOrder->update([
                'sales_order_id' => $salesOrder->id,
                'sales_order_no' => $salesOrder->order_no,
            ]);

            DB::commit();
        } catch (QueryException $e) {
            DB::rollBack();
            // 幂等键唯一约束并发竞态：另一请求已建成，返回已建订单。
            if ($idempotencyKey !== null && $idempotencyKey !== '' && $this->isUniqueViolation($e)) {
                $existing = MallOrder::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing->load('items');
                }
            }
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $mallOrder->load('items');
    }

    /** 明细归一化：读 products、算三档金额、校验库存，返回 rows + 汇总 */
    private function normalizeItems(array $items, int $warehouseId): array
    {
        $rows = [];
        $totalAmount = 0.0;
        $totalQty = 0;

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $product = Product::find($productId);
            if (! $product) {
                throw new \DomainException('商品不存在（ID: '.$productId.'）');
            }

            $qtyLarge = max(0, (int) ($item['qty_large'] ?? 0));
            $qtyMedium = max(0, (int) ($item['qty_medium'] ?? 0));
            $qtySmall = max(0, (int) ($item['qty_small'] ?? 0));
            $quantity = $this->toSmallUnit($product, $qtyLarge, $qtyMedium, $qtySmall);

            if ($quantity <= 0) {
                throw new \DomainException('「'.$product->name.'」数量不能为 0');
            }

            // 单一数据源：价一律取 products 当前价
            $priceLarge = (float) ($product->price_large ?? 0);
            $priceMedium = (float) ($product->price_medium ?? 0);
            $priceSmall = (float) ($product->price_small ?? 0);
            $amount = round($qtyLarge * $priceLarge + $qtyMedium * $priceMedium + $qtySmall * $priceSmall, 2);

            if ($amount <= 0) {
                throw new \DomainException('「'.$product->name.'」售价不能为 0');
            }

            // 库存校验：可用 = quantity - frozen_qty
            $stock = Stock::where('product_id', $productId)->where('warehouse_id', $warehouseId)->first();
            $available = $stock ? max(0, (int) $stock->quantity - (int) $stock->frozen_qty) : 0;
            if ($quantity > $available) {
                throw new \DomainException('「'.$product->name.'」库存不足（可用 '.$available.'，需要 '.$quantity.'）');
            }

            $rows[] = [
                'product_id' => $productId,
                'product' => $product,
                'qty_large' => $qtyLarge,
                'qty_medium' => $qtyMedium,
                'qty_small' => $qtySmall,
                'quantity' => $quantity,
                'price_large' => $priceLarge,
                'price_medium' => $priceMedium,
                'price_small' => $priceSmall,
                'price' => $priceSmall,
                'amount' => $amount,
            ];
            $totalAmount += $amount;
            $totalQty += $quantity;
        }

        return [
            'rows' => $rows,
            'total_amount' => round($totalAmount, 2),
            'total_qty' => $totalQty,
        ];
    }

    /** 创建申报单（sales_orders + sales_order_items），口径对齐 SalesOrderController::store */
    private function createDeclaration(MallOrder $mallOrder, array $rows, int $customerId, int $warehouseId, int $operatorId, string $operatorName): SalesOrder
    {
        $salesOrder = SalesOrder::create([
            'order_no' => 'SO'.date('YmdHis').strtoupper(Str::random(4)),
            'order_type' => 'normal',
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseId,
            'order_date' => $mallOrder->order_date->toDateString(),
            'delivery_date' => $mallOrder->order_date->toDateString(),
            'salesman_id' => null,
            'salesman_name' => null,
            'sort_type' => 'entry',
            'print_type' => 'none',
            'status' => 'pending',
            'total_amount' => $rows['total_amount'],
            'total_qty' => $rows['total_qty'],
            'paid_amount' => 0,
            'created_by' => $operatorId,
            'remark' => '商城订单 '.$mallOrder->order_no.' 自动生成',
        ]);

        foreach ($rows['rows'] as $row) {
            SalesOrderItem::create([
                'sales_order_id' => $salesOrder->id,
                'product_id' => $row['product_id'],
                'quantity' => $row['quantity'],
                'qty_large' => $row['qty_large'],
                'qty_medium' => $row['qty_medium'],
                'qty_small' => $row['qty_small'],
                'price' => $row['price'],
                'price_large' => $row['price_large'],
                'price_medium' => $row['price_medium'],
                'price_small' => $row['price_small'],
                'amount' => $row['amount'],
                'sale_mode' => '正常销售',
            ]);
        }

        // 下单即冻结库存（对齐 F1「下单即冻结」语义）
        foreach ($rows['rows'] as $row) {
            $this->stocks->freeze(
                $row['product_id'],
                $warehouseId,
                $row['quantity'],
                (int) $salesOrder->id,
                'SalesOrder',
                'sale_freeze',
                '商城订单 '.$mallOrder->order_no.' 冻结'
            );
        }

        return $salesOrder;
    }

    /** 折算小单位总量：大×conv×convMd + 中×convMd + 小 */
    private function toSmallUnit(Product $product, int $large, int $medium, int $small): int
    {
        $conv = (float) ($product->unit_conversion ?? 0);
        $convMd = (float) ($product->unit_conversion_medium ?? 0);

        $qty = $convMd > 0
            ? $large * $conv * $convMd + $medium * $convMd + $small
            : $large * $conv + $small;

        return (int) round($qty);
    }

    /** 判断是否唯一约束冲突（SQLite/MySQL 报错码不同，统一按消息判断） */
    private function isUniqueViolation(QueryException $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'unique') || str_contains($msg, 'duplicate') || str_contains($msg, '1062');
    }

    /** 生成商城订单号 MALL+Ymd+6位 */
    private function nextMallOrderNo(): string
    {
        do {
            $no = 'MALL'.date('Ymd').str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (MallOrder::where('order_no', $no)->exists());

        return $no;
    }
}
