<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 车销业务地基：车辆伪装成仓库。
 *
 * 车销的核心是"车上库存"——每辆车相当于一个移动仓库。为复用现有 stocks 表与
 * StockService::stockIn/stockOut（第二参数即 warehouse_id，零改动），给 warehouses
 * 表加 type（normal/vehicle）与 vehicle_id 列，并为每辆已有车辆回填一条 type='vehicle'
 * 的 warehouse 记录。后续所有车销单据的"车上库存"操作，warehouse_id 即指向这条
 * 车辆仓记录。
 *
 * 侵入面控制：warehouses 列表的 5 处后端消费方与 17 处前端调用点，在车销页面之外
 * 一律按 type='normal' 过滤，避免车辆仓混入普通业务下拉（见各消费方对应改动）。
 * WarehouseController::index 支持 type 参数，向后兼容（不传则返回全部）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('warehouses', 'type')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->string('type', 20)->default('normal')->after('is_active')->comment('仓库类型: normal 普通仓 / vehicle 车辆仓');
                $table->unsignedBigInteger('vehicle_id')->nullable()->after('type')->comment('关联车辆ID(type=vehicle时)');
            });
        }

        // 数据回填：为每辆已存在的车辆插入一条 type='vehicle' 的 warehouse 记录（按 vehicle_id 判重幂等）
        if (Schema::hasTable('vehicles')) {
            $vehicles = DB::table('vehicles')->get(['id', 'plate_no', 'is_active']);
            foreach ($vehicles as $v) {
                $exists = DB::table('warehouses')->where('vehicle_id', $v->id)->exists();
                if (! $exists) {
                    DB::table('warehouses')->insert([
                        'code' => 'VH'.$v->plate_no,
                        'name' => '车辆-'.$v->plate_no,
                        'type' => 'vehicle',
                        'vehicle_id' => $v->id,
                        'is_active' => (bool) $v->is_active,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // 回滚前先删除回填的车辆仓记录，再删列，避免删列时残留数据引用
        if (Schema::hasColumn('warehouses', 'vehicle_id')) {
            DB::table('warehouses')->where('type', 'vehicle')->delete();
        }
        if (Schema::hasColumn('warehouses', 'type')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->dropColumn(['type', 'vehicle_id']);
            });
        }
    }
};
