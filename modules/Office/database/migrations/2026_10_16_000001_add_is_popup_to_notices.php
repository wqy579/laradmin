<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 公告弹窗：notices 加 is_popup 列。
 *
 * 规格要求「用户登录时，如果有弹窗公告未读，自动弹出」。这里只存「这条公告要不要
 * 弹窗」这一静态意图；「某人是否已经看过」不落在本列上——它属于个人私有状态，
 * 由 messages 表的 is_read 承担（发布公告时按收件人各插一行，见 NoticeController
 * ::notifyAll）。这样同一个弹窗公告对 A 弹过、B 未读时各自独立，与铃铛未读角标、
 * 消息中心「未读/已读」同一套语义，不需要再造一份「谁弹过」的状态表。
 *
 * 默认 false：存量公告一律不弹窗，避免给现有用户突然弹一堆历史公告。只有管理员
 * 显式勾了「登录弹窗」的新公告才会弹。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notices') && ! Schema::hasColumn('notices', 'is_popup')) {
            Schema::table('notices', function (Blueprint $table) {
                $table->boolean('is_popup')->default(false)->comment('是否登录弹窗公告')
                    ->after('is_top');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notices') && Schema::hasColumn('notices', 'is_popup')) {
            Schema::table('notices', function (Blueprint $table) {
                $table->dropColumn('is_popup');
            });
        }
    }
};
