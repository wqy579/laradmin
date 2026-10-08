<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 报表查询模版（通用交互规范第 2 条：保存当前查询条件为模版，下次快速选择）。
 *
 * 一个模版 = 一份查询条件的 JSON 快照，按 report 键区分归属页面
 * （sales / stock / salesman / combined / recent-prices），
 * is_public=1 的模版同页面所有人可见，否则只有创建人可见。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_templates')) {
            Schema::create('report_templates', function (Blueprint $table) {
                $table->id();
                $table->string('report', 50)->comment('归属报表：sales/stock/salesman/combined/recent-prices');
                $table->string('name', 100)->comment('模版名称');
                $table->json('conditions')->comment('查询条件快照');
                $table->unsignedBigInteger('created_by')->nullable()->comment('创建人');
                $table->string('creator_name', 50)->nullable()->comment('创建人姓名快照');
                $table->boolean('is_public')->default(false)->comment('是否共享给所有人');
                $table->timestamps();

                $table->index('report');
                $table->index(['report', 'created_by']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_templates');
    }
};
