<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 总账管理 · 会计科目表
 *
 * 《小企业会计准则》标准科目，编码、类别、余额方向均按准则写死（见 SubjectSeeder）。
 * parent_id 自关联构成科目树；is_leaf=true 的末级科目才允许在凭证分录里被选中。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_subjects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->default(0)->comment('上级科目，0 为顶级');
            $table->string('code', 40)->unique()->comment('科目编码，层级规则：父级编码+本级序号');
            $table->string('name', 80)->comment('科目名称');
            $table->string('category', 20)->comment('asset/liability/common/equity/cost/profit_loss');
            $table->string('balance_direction', 10)->comment('debit/credit 余额方向');
            $table->boolean('is_leaf')->default(true)->comment('是否末级');
            $table->unsignedInteger('level')->default(1)->comment('级次');
            $table->json('aux_types')->nullable()->comment('辅助核算类型 JSON: customer/supplier/employee/department/project');
            $table->integer('sort')->default(0)->comment('越小越靠前');
            $table->string('status', 20)->default('enabled')->comment('enabled/disabled');
            $table->string('remark')->nullable();
            $table->timestamps();

            $table->index('parent_id');
            $table->index('category');
            $table->index(['status', 'is_leaf']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_subjects');
    }
};
