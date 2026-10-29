<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E8 仪表盘布局——按用户持久化。
 *
 * 编辑布局（拖拽排序）在 E8 前只存浏览器 localStorage（pinia-plugin-persistedstate），
 * 换设备/换浏览器即丢，也不跨用户隔离。本表把布局落到服务端 auth_user 维度：
 * 每个用户一行，layout 存 GridLayout 的 JSON 数组，用户登录后按 user_id 读取/覆写。
 *
 * 只存 Dashboard 布局，不承担通用系统偏好——避免一张表什么都装。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_user_preference', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique()->comment('auth_user.id');
            $table->json('layout')->nullable()->comment('仪表盘 GridLayout JSON 数组');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_user_preference');
    }
};
