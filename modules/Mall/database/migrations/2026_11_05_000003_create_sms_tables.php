<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F5 短信模块 —— 短信模板表（sms_templates）。
 *
 * 模板体用 {变量名} 占位，前端预览时按占位名去示例值映射表里替换真实示例值
 * （如 {客户名} → 「张三」）。变量列表用 JSON 存于 variables 列，避免每次解析体。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sms_templates')) {
            Schema::create('sms_templates', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50)->unique()->comment('模板编码(唯一, 用于程序取用)');
                $table->string('name', 100)->comment('模板名称');
                $table->string('content', 500)->comment('模板内容(含{变量}占位)');
                $table->json('variables')->nullable()->comment('变量示例值映射 {变量名: 示例值}');
                $table->string('remark', 255)->nullable()->comment('备注');
                $table->tinyInteger('status')->default(1)->comment('状态: 1启用 0停用');
                $table->foreignId('created_by')->nullable()->constrained('auth_user')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sms_records')) {
            Schema::create('sms_records', function (Blueprint $table) {
                $table->id();
                $table->string('send_no', 40)->unique()->comment('发送编号 SMS+YmdHis+4位');
                $table->unsignedBigInteger('template_id')->nullable()->comment('模板ID(可空: 直发)');
                $table->string('template_code', 50)->nullable()->comment('模板编码快照');
                $table->string('template_name', 100)->nullable()->comment('模板名快照');
                $table->string('mobile', 30)->comment('接收手机号');
                $table->string('receiver_name', 50)->nullable()->comment('接收人姓名(快照)');
                $table->string('content', 500)->comment('实际发送内容(变量已替换)');
                $table->json('variables')->nullable()->comment('本次替换的变量值 {变量名: 值}');
                $table->string('status', 20)->default('pending')->comment('状态: pending发送中 success成功 failed失败');
                $table->string('error_message', 255)->nullable()->comment('失败原因');
                $table->timestamp('sent_at')->nullable()->comment('发送完成时间');
                $table->unsignedBigInteger('created_by')->nullable()->comment('发送操作人');
                $table->string('creator_name', 50)->nullable()->comment('发送人姓名快照');
                $table->timestamps();

                $table->index('mobile');
                $table->index('status');
                $table->index('template_id');
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_records');
        Schema::dropIfExists('sms_templates');
    }
};
