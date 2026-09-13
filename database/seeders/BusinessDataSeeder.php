<?php

namespace Database\Seeders;

use App\Models\Business\Product;
use App\Models\Business\ProductCategory;
use App\Models\Business\Warehouse;
use App\Models\Business\Supplier;
use App\Models\Business\Customer;
use App\Models\Business\Vehicle;
use App\Models\Business\Route;
use App\Models\Business\Employee;
use Illuminate\Database\Seeder;

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

        // 产品分类
        $categories = [
            ['name' => '饮料', 'product_count' => 0],
            ['name' => '食品', 'product_count' => 0],
            ['name' => '日用品', 'product_count' => 0],
            ['name' => '电子产品', 'product_count' => 0],
            ['name' => '服装', 'product_count' => 0],
        ];
        foreach ($categories as $cat) {
            ProductCategory::firstOrCreate(['name' => $cat['name']], $cat);
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
                'category_id' => 1,
                'name' => '矿泉水 550ml',
                'code' => 'P001',
                'spec' => '550ml/瓶',
                'price_large' => 2.00,
                'price_small' => 2.00,
                'cost_price' => 1.00,
                'stock_qty' => 1000,
                'is_online' => true,
                'is_active' => true,
            ],
            [
                'category_id' => 1,
                'name' => '可乐 330ml',
                'code' => 'P002',
                'spec' => '330ml/罐',
                'price_large' => 3.50,
                'price_small' => 3.50,
                'cost_price' => 2.00,
                'stock_qty' => 500,
                'is_online' => true,
                'is_active' => true,
            ],
            [
                'category_id' => 2,
                'name' => '巧克力饼干',
                'code' => 'P003',
                'spec' => '100g/包',
                'price_large' => 8.00,
                'price_small' => 8.00,
                'cost_price' => 5.00,
                'stock_qty' => 200,
                'is_online' => true,
                'is_active' => true,
            ],
            [
                'category_id' => 3,
                'name' => '洗衣液 2kg',
                'code' => 'P004',
                'spec' => '2kg/瓶',
                'price_large' => 25.00,
                'price_small' => 25.00,
                'cost_price' => 15.00,
                'stock_qty' => 100,
                'is_online' => true,
                'is_active' => true,
            ],
            [
                'category_id' => 4,
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

        // 更新产品分类数量
        foreach (ProductCategory::all() as $category) {
            $category->update(['product_count' => $category->products()->count()]);
        }

        $this->command->info('Business test data seeded successfully.');
    }
}
