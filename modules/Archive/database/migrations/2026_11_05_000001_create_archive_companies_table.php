<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 公司档案（F6）。
 *
 * 连凯「资料」域里的「公司档案」在本系统此前没有对应实体：auth_department 是
 * 组织树（给权限/审批找人用，无编码与开票要素），employees.department 是自由文本，
 * 都不能承载法人主体信息（纳税人识别号、开票名称、开户行、默认主体）。
 *
 * 因此单独建表而不是改造 auth_department：后者的结构与语义由 Auth 模块与权限体系
 * 共同依赖，往里塞开票要素会把两套关注点搅在一起。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('archive_companies')) {
            Schema::create('archive_companies', function (Blueprint $table) {
                $table->id();
                // 编码可选（连凯现场部分主体只用名称），留空必须存 NULL：
                // 唯一索引下多行 '' 会互相撞，多行 NULL 不会
                $table->string('code', 30)->nullable()->unique()->comment('公司编码');
                $table->string('name', 100)->comment('公司名称');
                $table->string('short_name', 50)->nullable()->comment('公司简称');
                $table->string('tax_no', 30)->nullable()->comment('纳税人识别号');
                $table->string('invoice_title', 100)->nullable()->comment('开票名称');
                $table->string('bank_name', 100)->nullable()->comment('开户银行');
                $table->string('bank_account', 40)->nullable()->comment('银行账号');
                $table->string('contact_name', 30)->nullable()->comment('联系人');
                $table->string('contact_phone', 20)->nullable()->comment('联系电话');
                $table->string('address', 200)->nullable()->comment('公司地址');
                $table->boolean('is_default')->default(false)->comment('默认公司');
                $table->string('status', 20)->default('enabled')->comment('enabled启用/disabled停用');
                $table->unsignedInteger('sort')->default(0)->comment('排序');
                $table->string('remark', 255)->nullable()->comment('备注');
                $table->softDeletes();
                $table->timestamps();

                $table->index('status');
                $table->index('sort');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_companies');
    }
};
