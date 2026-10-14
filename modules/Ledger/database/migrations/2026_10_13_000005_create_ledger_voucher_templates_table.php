<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 总账管理 · 凭证模板表（可选）
 *
 * 可把常用凭证存为模板，调入时复刻分录结构。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_voucher_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->comment('模板名称');
            $table->json('template_data')->comment('分录模板 JSON');
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->string('creator_name', 60)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_voucher_templates');
    }
};
