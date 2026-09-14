<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建 System 模块的所有表
     */
    public function up(): void
    {
        // ==================== 系统配置表 ====================
        Schema::create('system_setting', function (Blueprint $table) {
            $table->comment('系统配置表');
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable()->comment('父级ID');
            $table->string('item_type', 20)->default('config')->comment('条目类型：group-分组, config-配置项');
            $table->string('group')->comment('配置分组');
            $table->string('key')->unique()->comment('配置键');
            $table->string('name')->comment('配置名称');
            $table->string('type')->default('string')->comment('字段类型：string, text, number, boolean, select, radio, checkbox, file, json');
            $table->text('options')->nullable()->comment('选项配置(JSON格式)');
            $table->text('value')->nullable()->comment('配置值');
            $table->string('default_value')->nullable()->comment('默认值');
            $table->text('description')->nullable()->comment('配置说明');
            $table->string('validation')->nullable()->comment('验证规则');
            $table->integer('sort')->default(0)->comment('排序');
            $table->boolean('is_system')->default(false)->comment('是否系统配置');
            $table->boolean('status')->default(true)->comment('状态');
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
            $table->index('group');
            $table->index('key');
        });

        // ==================== 系统日志表 ====================
        Schema::create('system_log', function (Blueprint $table) {
            $table->comment('系统日志表');
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->comment('用户ID');
            $table->string('username')->nullable()->comment('用户名');
            $table->string('module')->comment('模块名称');
            $table->string('action')->comment('操作类型');
            $table->string('method')->comment('请求方法');
            $table->string('url')->comment('请求URL');
            $table->string('ip')->nullable()->comment('IP地址');
            $table->text('user_agent')->nullable()->comment('用户代理');
            $table->json('params')->nullable()->comment('请求参数');
            $table->text('result')->nullable()->comment('操作结果');
            $table->integer('status_code')->default(200)->comment('状态码');
            $table->string('status')->default('success')->comment('状态');
            $table->text('error_message')->nullable()->comment('错误信息');
            $table->integer('execution_time')->nullable()->comment('执行时间(毫秒)');
            $table->timestamps();

            $table->index('user_id');
            $table->index('module');
            $table->index('action');
            $table->index('created_at');
        });

        // ==================== 系统字典表 ====================
        Schema::create('system_dictionary', function (Blueprint $table) {
            $table->comment('系统字典表');
            $table->id();
            $table->string('name')->comment('字典名称');
            $table->string('code')->unique()->comment('字典编码');
            $table->text('description')->nullable()->comment('字典描述');
            $table->string('value_type')->default('string')->comment('值类型：string, number, boolean, json');
            $table->boolean('status')->default(true)->comment('状态');
            $table->integer('sort')->default(0)->comment('排序');
            $table->timestamps();
            $table->softDeletes();
        });

        // ==================== 系统字典项表 ====================
        Schema::create('system_dictionary_item', function (Blueprint $table) {
            $table->comment('系统字典项表');
            $table->id();
            $table->unsignedBigInteger('dictionary_id')->comment('字典ID');
            $table->string('label')->comment('标签名称');
            $table->string('value')->comment('标签值');
            $table->string('color')->nullable()->comment('颜色标识');
            $table->text('description')->nullable()->comment('描述');
            $table->boolean('is_default')->default(false)->comment('是否默认');
            $table->boolean('status')->default(true)->comment('状态');
            $table->integer('sort')->default(0)->comment('排序');
            $table->timestamps();

            $table->foreign('dictionary_id')->references('id')->on('system_dictionary')->onDelete('cascade');
            $table->index('dictionary_id');
        });

        // ==================== 定时调度任务表 ====================
        Schema::create('system_scheduled', function (Blueprint $table) {
            $table->comment('定时调度任务表');
            $table->id();
            $table->string('name', 100)->comment('任务名称');
            $table->string('command')->comment('执行命令');
            $table->text('description')->nullable()->comment('任务描述');
            $table->string('type', 20)->default('artisan')->comment('任务类型：artisan, job, shell');
            $table->string('expression', 100)->nullable()->comment('Cron表达式(5字段)');
            $table->unsignedInteger('interval')->nullable()->comment('执行间隔(秒)，与expression二选一');
            $table->string('timezone', 50)->default('Asia/Shanghai')->comment('时区');
            $table->string('status', 20)->default('idle')->comment('任务状态：idle, running, paused, stopped, error');
            $table->unsignedInteger('timeout')->default(300)->comment('超时时间(秒)');
            $table->boolean('without_overlapping')->default(false)->comment('防止重叠执行');
            $table->unsignedInteger('max_tries')->default(3)->comment('最大重试次数');
            $table->json('parameters')->nullable()->comment('任务参数(JSON)');
            $table->timestamp('last_run_at')->nullable()->comment('最后运行时间');
            $table->timestamp('next_run_at')->nullable()->comment('下次运行时间');
            $table->timestamp('started_at')->nullable()->comment('当前运行开始时间');
            $table->unsignedInteger('run_count')->default(0)->comment('运行次数');
            $table->unsignedInteger('error_count')->default(0)->comment('错误次数');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        // ==================== 调度任务执行日志表 ====================
        Schema::create('system_scheduled_log', function (Blueprint $table) {
            $table->comment('调度任务执行日志表');
            $table->id();
            $table->unsignedBigInteger('scheduled_id')->comment('关联调度任务ID');
            $table->string('status', 20)->comment('执行状态：running, success, failed, timeout');
            $table->text('output')->nullable()->comment('执行输出');
            $table->text('error_message')->nullable()->comment('错误信息');
            $table->unsignedInteger('execution_time')->nullable()->comment('执行耗时(毫秒)');
            $table->timestamp('started_at')->nullable()->comment('开始时间');
            $table->timestamp('finished_at')->nullable()->comment('结束时间');
            $table->timestamps();

            $table->foreign('scheduled_id')->references('id')->on('system_scheduled')->onDelete('cascade');
            $table->index('scheduled_id');
            $table->index('status');
            $table->index('started_at');
        });

        // ==================== 系统通知表 ====================
        Schema::create('system_notification', function (Blueprint $table) {
            $table->comment('系统通知表');
            $table->id();
            $table->json('user_ids')->nullable()->comment('用户ID数组（支持多用户）');
            $table->json('department_ids')->nullable()->comment('部门ID数组（支持多部门）');
            $table->string('title')->comment('通知标题');
            $table->text('content')->comment('通知内容');
            $table->string('type')->default('info')->comment('通知类型：info, success, warning, error, task, system');
            $table->string('category')->nullable()->comment('通知分类：system, task, message, reminder, announcement');
            $table->json('data')->nullable()->comment('附加数据(JSON格式)');
            $table->json('action_data')->nullable()->comment('操作数据(JSON数组格式，支持多个操作按钮)');
            $table->boolean('is_read')->default(false)->comment('是否已读');
            $table->timestamp('read_at')->nullable()->comment('阅读时间');
            $table->boolean('sent_via_websocket')->default(false)->comment('是否已通过WebSocket发送');
            $table->timestamp('sent_at')->nullable()->comment('发送时间');
            $table->integer('retry_count')->default(0)->comment('重试次数');
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_read');
            $table->index('type');
            $table->index('category');
            $table->index('created_at');
        });

        // ==================== 附件表 ====================
        Schema::create('system_attachment', function (Blueprint $table) {
            $table->comment('附件表');
            $table->id();
            $table->string('name')->comment('原始文件名');
            $table->string('file_name')->comment('存储文件名');
            $table->string('path')->comment('存储路径');
            $table->string('url')->nullable()->comment('访问URL');
            $table->string('mime_type')->nullable()->comment('MIME类型');
            $table->string('extension', 20)->nullable()->comment('文件扩展名');
            $table->unsignedBigInteger('size')->default(0)->comment('文件大小(字节)');
            $table->string('type')->default('file')->comment('文件类型：image, document, video, audio, archive, other');
            $table->string('storage_driver')->default('local')->comment('存储驱动');
            $table->string('hash', 64)->nullable()->comment('文件哈希(用于秒传检测)');
            $table->unsignedBigInteger('user_id')->nullable()->comment('上传用户ID');
            $table->string('directory')->nullable()->comment('存储目录');
            $table->json('metadata')->nullable()->comment('元数据(宽高、时长等)');
            $table->string('description')->nullable()->comment('文件描述');
            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
            $table->index('extension');
            $table->index('storage_driver');
            $table->index('user_id');
            $table->index('hash');
            $table->index('created_at');
        });
    }

    /**
     * 回滚迁移
     */
    public function down(): void
    {
        // 按依赖关系逆序删除表
        Schema::dropIfExists('system_attachment');
        Schema::dropIfExists('system_notification');
        Schema::dropIfExists('system_scheduled_log');
        Schema::dropIfExists('system_scheduled');
        Schema::dropIfExists('system_dictionary_item');
        Schema::dropIfExists('system_dictionary');
        Schema::dropIfExists('system_setting');
        Schema::dropIfExists('system_log');
    }
};
