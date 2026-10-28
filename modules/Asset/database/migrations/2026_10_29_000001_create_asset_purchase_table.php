<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 固定资产 · 购置单表（D3）
 *
 * original_value 由后端按 数量×单价 重算落库，前端传值不作数；
 * voucher_id 是「已登记现金支出凭证」的唯一凭据（Ledger 侧无 source 字段，
 * 反向只能由本列持有），未勾选凭证时恒为 null。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_purchase')) {
            return;
        }

        Schema::create('asset_purchase', function (Blueprint $table) {
            $table->id();
            $table->string('purchase_no', 32)->comment('购置单号 GZ-yyyymm-####');
            $table->date('purchase_date')->comment('购置日期');
            $table->string('asset_name', 100)->comment('资产名称');
            $table->string('spec', 100)->nullable()->comment('规格型号');
            $table->unsignedInteger('quantity')->default(1)->comment('数量');
            $table->decimal('unit_price', 14, 2)->default(0)->comment('单价');
            $table->decimal('original_value', 14, 2)->default(0)->comment('原值 = 数量×单价（后端重算）');
            $table->unsignedInteger('use_months')->default(60)->comment('预计使用月份');
            $table->decimal('residual_rate', 5, 2)->default(5)->comment('残值率 %');
            $table->string('dep_method', 20)->default('straight')->comment('折旧方式: straight/average');
            $table->unsignedBigInteger('department_id')->nullable()->comment('使用部门');
            $table->string('department_name', 60)->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->comment('使用人（后台用户）');
            $table->string('user_name', 60)->nullable();
            $table->string('supplier', 100)->nullable()->comment('供应商');
            $table->unsignedBigInteger('voucher_id')->nullable()->comment('现金支出凭证ID，null=未登记');
            $table->string('remark', 255)->nullable();
            $table->string('status', 20)->default('normal')->comment('normal/void');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique('purchase_no');
            $table->index('purchase_date');
            $table->index('asset_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_purchase');
    }
};
