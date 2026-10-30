<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 事业部档案（F6）。
 *
 * 连凯「资料」域的第 6 项。事业部挂在法人公司之下（一司多部），是绩效/提成
 * 汇总的组织维度，与 auth_department（权限组织树）不是一回事，故独立建表。
 *
 * company_id 用 unsignedBigInteger + 索引、不加数据库外键，与本仓库主数据表
 * 既有做法一致（brands.brand_id、agr_agreements.customer_id 同样不加 FK）：
 * 主数据两侧都走软删，物理外键在软删场景下不提供额外保护，却会让 sqlite 与
 * MySQL 的迁移/回滚行为出现差异。引用完整性由 DivisionController 在写入时校验。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('archive_divisions')) {
            Schema::create('archive_divisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->comment('所属公司ID(archive_companies.id)');
                // 同公司档案：编码可留空，留空存 NULL 而不是 ''（唯一索引下 '' 会互撞）
                $table->string('code', 30)->nullable()->unique()->comment('事业部编码');
                $table->string('name', 100)->comment('事业部名称');
                $table->string('manager_name', 30)->nullable()->comment('负责人');
                $table->string('manager_phone', 20)->nullable()->comment('负责人电话');
                $table->date('effective_at')->nullable()->comment('启用日期');
                $table->string('status', 20)->default('enabled')->comment('enabled启用/disabled停用');
                $table->unsignedInteger('sort')->default(0)->comment('排序');
                $table->string('remark', 255)->nullable()->comment('备注');
                $table->softDeletes();
                $table->timestamps();

                $table->index('company_id');
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_divisions');
    }
};
