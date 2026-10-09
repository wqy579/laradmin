<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 配送任务取消退货入库（开发文档 §5.4/§5.5.6）
 *
 * 取消任务后生成退货入库单、商品退回仓库增加库存。
 * 需要：已取消状态、取消原因、退货入库单引用。
 * 异常类型补「联系不上客户 / 其他」两值（字段本身 string(20) 已够用，仅补注释）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('delivery_task')) {
            if (! Schema::hasColumn('delivery_task', 'cancel_reason')) {
                Schema::table('delivery_task', function (Blueprint $table) {
                    $table->text('cancel_reason')->nullable()->comment('取消原因')->after('exception_remark');
                    $table->unsignedBigInteger('return_id')->nullable()->comment('退货入库单ID')->after('cancel_reason');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('delivery_task')) {
            Schema::table('delivery_task', function (Blueprint $table) {
                if (Schema::hasColumn('delivery_task', 'cancel_reason')) {
                    $table->dropColumn('cancel_reason');
                }
                if (Schema::hasColumn('delivery_task', 'return_id')) {
                    $table->dropColumn('return_id');
                }
            });
        }
    }
};
