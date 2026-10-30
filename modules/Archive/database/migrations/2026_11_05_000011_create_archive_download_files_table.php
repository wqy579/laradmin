<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 下载中心文件表（F6 块3）。
 *
 * 刻意不复用 System 模块的 system_attachment：那要给 Archive 加一条指向
 * Modules\System 的架构边（ArchitectureTest 白名单），而且附件服务的存储策略
 * 归别人维护，下载中心这种"必须长期可下载、必须限速限类型"的语义跟它并不一致。
 *
 * 关键点：**file_name 只用于展示，stored_name 才是磁盘文件名**，
 * 且 stored_name 由系统随机生成，永远不含用户上传的任何字符（见 DownloadFileService），
 * 因此路径穿越、.php 落地、空字节截断这三类上传攻击都无路径可走。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_download_files', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->comment('展示名称');
            $table->string('category', 50)->default('模板')->comment('分组：模板/手册/其它');
            $table->string('file_name', 190)->comment('原始文件名，仅用于展示与下载时的文件名');
            $table->string('stored_name', 190)->comment('磁盘随机文件名，绝不由用户输入拼成');
            $table->string('extension', 20);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size')->default(0)->comment('字节');
            $table->string('version', 20)->nullable();
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->string('status', 20)->default('published')->comment('published 可下载 / disabled 已下架');
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name', 60)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('updated_by_name', 60)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('category');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_download_files');
    }
};
