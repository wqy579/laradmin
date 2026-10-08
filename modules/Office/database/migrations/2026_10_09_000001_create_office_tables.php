<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 办公管理：内部邮件 + 公司公告。
 *
 * 邮件拆成 mails（正文）+ mail_recipients（每人一份的读/删状态）：
 * 已读、回收站都是「每个人各自的状态」，不能记在邮件主体上，
 * 否则 A 删进回收站会把 B 收件箱里的同一封邮件一起带走。
 *
 * 附件不单独建表：邮件/公告的附件就是上传记录的 URL 列表，
 * 生命周期跟正文一致（正文删了附件就没意义），存 JSON 列避免多一次 join。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mails')) {
            Schema::create('mails', function (Blueprint $table) {
                $table->id();
                $table->string('subject', 200)->default('')->comment('主题');
                $table->longText('content')->nullable()->comment('正文（富文本）');
                $table->unsignedBigInteger('from_user_id')->nullable()->comment('发件人');
                $table->string('from_name', 50)->nullable()->comment('发件人姓名快照');
                $table->string('status', 20)->default('draft')->comment('draft草稿 sent已发送');
                $table->json('attachments')->nullable()->comment('附件列表 [{name,url,size}]');
                $table->timestamp('sent_at')->nullable()->comment('发送时间');
                $table->softDeletes()->comment('发件人侧删除（进自己的回收站）');
                $table->timestamps();

                $table->index(['from_user_id', 'status']);
                $table->index('sent_at');
            });
        }

        if (! Schema::hasTable('mail_recipients')) {
            Schema::create('mail_recipients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('mail_id');
                $table->unsignedBigInteger('user_id')->comment('收/抄送人');
                $table->string('type', 10)->default('to')->comment('to收件人 cc抄送');
                $table->boolean('is_read')->default(false)->comment('是否已读');
                $table->timestamp('read_at')->nullable();
                $table->softDeletes()->comment('收件人侧删除（进自己的回收站）');
                $table->timestamps();

                $table->index(['user_id', 'type', 'is_read']);
                $table->index('mail_id');
            });
        }

        if (! Schema::hasTable('notices')) {
            Schema::create('notices', function (Blueprint $table) {
                $table->id();
                $table->string('title', 200)->comment('公告标题');
                $table->string('type', 20)->default('notice')->comment('notice通知 rule制度 activity活动 other其他');
                $table->longText('content')->nullable()->comment('公告正文（富文本）');
                $table->boolean('is_top')->default(false)->comment('是否置顶');
                $table->string('scope', 20)->default('all')->comment('all全员 department指定部门 user指定人员');
                $table->json('scope_ids')->nullable()->comment('发布范围 ID 列表');
                $table->json('attachments')->nullable()->comment('附件列表');
                $table->unsignedBigInteger('author_id')->nullable()->comment('发布人');
                $table->string('author_name', 50)->nullable()->comment('发布人姓名快照');
                $table->unsignedInteger('view_count')->default(0)->comment('阅读量');
                $table->timestamps();

                $table->index('is_top');
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
        Schema::dropIfExists('mail_recipients');
        Schema::dropIfExists('mails');
    }
};
