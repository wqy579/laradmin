<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 消息通知中心：站内消息表 + 公告发布状态。
 *
 * 一、messages 表
 *   站内消息按「每个收件人一条记录」存（user_id 直接记在消息上），
 *   而不是 system_notification 的「user_ids JSON 数组 + 一条记录对多人」。
 *   原因：铃铛/消息中心的已读态是「每个人各自的状态」，与 modules/Office
 *   内部邮件 mail_recipients 同一设计——A 标记已读不能连带把 B 的读态改掉，
 *   也不好在一条消息上塞「谁读过」的嵌套 JSON。
 *
 *   type 决定左侧图标（审批/库存预警/系统公告/业务通知），category 是可选的
 *   细分；payload 放跳转路由与业务实体引用，前端「查看详情」据此跳转。
 *
 * 二、notices 加 status / published_at
 *   公告此前「存即发布」，无草稿态。本迁移加 status(draft/published) 与
 *   published_at，配套 NoticeController::publish() 实现「保存草稿 / 发布」双按钮。
 *   存量公告 status 默认 published、published_at 回填 created_at，行为不变。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messages')) {
            Schema::create('messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->comment('收件人');
                $table->string('type', 30)->default('business')->comment('类型：approval审批 stock_warning库存预警 system系统公告 business业务通知');
                $table->string('category', 30)->nullable()->comment('细分分类');
                $table->string('title', 200)->comment('消息标题');
                $table->longText('content')->nullable()->comment('消息内容/摘要');
                $table->json('payload')->nullable()->comment('业务载荷 {entity_type, entity_id, path, extra}');
                $table->boolean('is_read')->default(false)->comment('是否已读');
                $table->timestamp('read_at')->nullable()->comment('阅读时间');
                $table->softDeletes()->comment('收件人侧删除');
                $table->timestamps();

                $table->index(['user_id', 'is_read']);
                $table->index(['user_id', 'type']);
                $table->index(['user_id', 'created_at']);
            });
        }

        if (Schema::hasTable('notices') && ! Schema::hasColumn('notices', 'status')) {
            Schema::table('notices', function (Blueprint $table) {
                $table->string('status', 20)->default('published')->comment('draft草稿 published已发布')
                    ->after('view_count');
                $table->timestamp('published_at')->nullable()->comment('发布时间')
                    ->after('status');
                $table->index('status');
            });
            // 存量公告回填发布时间，避免列表排序与「已发布」筛选出现空值
            DB::table('notices')->whereNull('published_at')->update([
                'status' => 'published',
                'published_at' => DB::raw('created_at'),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');

        if (Schema::hasTable('notices') && Schema::hasColumn('notices', 'status')) {
            Schema::table('notices', function (Blueprint $table) {
                $table->dropColumn(['status', 'published_at']);
            });
        }
    }
};
