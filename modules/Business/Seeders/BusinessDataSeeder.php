<?php

namespace Modules\Business\Seeders;

use Illuminate\Database\Seeder;
use Modules\Order\Models\Customer;
use Modules\Order\Models\Supplier;
use Modules\Stock\Models\Product;
use Modules\Stock\Models\ProductCategory;
use Modules\Stock\Models\Vehicle;
use Modules\Stock\Models\Warehouse;

class BusinessDataSeeder extends Seeder
{
    public function run(): void
    {
        // 单位
        $units = ['个', '箱', '件', '包', '瓶', '盒'];
        foreach ($units as $unit) {
            \DB::table('units')->updateOrInsert(
                ['name' => $unit],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        // 产品分类（主分类）：updateOrCreate 回填 is_main/parent_id，
        // 并记录真实自增 id，供下面商品引用——避免硬编码 1/2/3/4 撞外键
        // （自增 id 不保证从 1 开始，旧写法在新库上必报 FK 1452）
        $categories = ['饮料', '食品', '日用品', '电子产品', '服装'];
        $catIds = [];
        foreach ($categories as $name) {
            $cat = ProductCategory::updateOrCreate(
                ['name' => $name],
                ['is_main' => true, 'parent_id' => null, 'is_active' => true]
            );
            $catIds[$name] = $cat->id;
        }

        // 仓库
        $warehouses = [
            ['code' => 'WH001', 'name' => '主仓库', 'address' => '曲靖市'],
            ['code' => 'WH002', 'name' => '退货仓库', 'address' => '曲靖市'],
        ];
        foreach ($warehouses as $wh) {
            Warehouse::firstOrCreate(['code' => $wh['code']], $wh);
        }

        // 供应商
        $suppliers = [
            ['code' => 'SUP001', 'name' => '供应商A', 'contact' => '张三', 'phone' => '13800138001'],
            ['code' => 'SUP002', 'name' => '供应商B', 'contact' => '李四', 'phone' => '13800138002'],
        ];
        foreach ($suppliers as $sup) {
            Supplier::firstOrCreate(['code' => $sup['code']], $sup);
        }

        // 客户
        $customers = [
            ['code' => 'CUST001', 'name' => '客户A', 'contact' => '王五', 'phone' => '13800138003'],
            ['code' => 'CUST002', 'name' => '客户B', 'contact' => '赵六', 'phone' => '13800138004'],
        ];
        foreach ($customers as $cust) {
            Customer::firstOrCreate(['code' => $cust['code']], $cust);
        }

        // 车辆
        $vehicles = [
            ['plate_no' => '京A12345', 'driver_name' => '司机甲', 'driver_phone' => '13800138005'],
            ['plate_no' => '京B67890', 'driver_name' => '司机乙', 'driver_phone' => '13800138006'],
        ];
        foreach ($vehicles as $veh) {
            Vehicle::firstOrCreate(['plate_no' => $veh['plate_no']], $veh);
        }

        // 产品
        $products = [
            [
                'main_category_id' => $catIds['饮料'],
                'name' => '矿泉水 550ml',
                'code' => 'P001',
                'spec' => '550ml/瓶',
                // 大小单位换算：1件=24瓶；price_large 为件价(=瓶价×换算率)
                'price_unit' => '件',
                'price_unit_small' => '瓶',
                'unit_conversion' => 24,
                'price_large' => 48.00,
                'price_small' => 2.00,
                'cost_price' => 1.00,
                'stock_qty' => 1000,
                'is_online' => true,
                'is_active' => true,
            ],
            [
                'main_category_id' => $catIds['饮料'],
                'name' => '可乐 330ml',
                'code' => 'P002',
                'spec' => '330ml/罐',
                // 大小单位换算：1件=24罐
                'price_unit' => '件',
                'price_unit_small' => '罐',
                'unit_conversion' => 24,
                'price_large' => 84.00,
                'price_small' => 3.50,
                'cost_price' => 2.00,
                'stock_qty' => 500,
                'is_online' => true,
                'is_active' => true,
            ],
            [
                'main_category_id' => $catIds['食品'],
                'name' => '巧克力饼干',
                'code' => 'P003',
                'spec' => '100g/包',
                // 大小单位换算：1件=20包
                'price_unit' => '件',
                'price_unit_small' => '包',
                'unit_conversion' => 20,
                'price_large' => 160.00,
                'price_small' => 8.00,
                'cost_price' => 5.00,
                'stock_qty' => 200,
                'is_online' => true,
                'is_active' => true,
            ],
            [
                'main_category_id' => $catIds['日用品'],
                'name' => '洗衣液 2kg',
                'code' => 'P004',
                'spec' => '2kg/瓶',
                // 大小单位换算：1件=6瓶
                'price_unit' => '件',
                'price_unit_small' => '瓶',
                'unit_conversion' => 6,
                'price_large' => 150.00,
                'price_small' => 25.00,
                'cost_price' => 15.00,
                'stock_qty' => 100,
                'is_online' => true,
                'is_active' => true,
            ],
            [
                'main_category_id' => $catIds['电子产品'],
                'name' => 'USB数据线',
                'code' => 'P005',
                'spec' => '1m',
                'price_large' => 15.00,
                'price_small' => 15.00,
                'cost_price' => 8.00,
                'stock_qty' => 300,
                'is_online' => true,
                'is_active' => true,
            ],
        ];
        foreach ($products as $prod) {
            Product::firstOrCreate(['code' => $prod['code']], $prod);
        }

        $this->command->info('Business test data seeded successfully.');
    }
}
