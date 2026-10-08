<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 小程序设置：按分组存一份配置 JSON。
 *
 * 不用 key-value 行：9 组配置每组十来个字段，行式存储要拼近百行默认值、
 * 读一次还得在 PHP 里按 key 重组。整组存一个 JSON 更贴近「一份表单 = 一份配置」。
 *
 * 没有的值一律回落到后端默认配置（MiniappSettingController::defaults），
 * 因此新增配置项不需要迁移数据，老数据只是「没配过」，读出来就是默认值。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('miniapp_settings')) {
            Schema::create('miniapp_settings', function (Blueprint $table) {
                $table->id();
                $table->string('group', 30)->unique()->comment('配置分组 basic/home/category/payment/notify/delivery/member/coupon/about');
                $table->json('config')->nullable()->comment('该组配置 JSON');
                $table->unsignedBigInteger('updated_by')->nullable()->comment('最后修改人');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('miniapp_settings');
    }
};
