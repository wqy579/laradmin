<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 帮助文档表（F6 块3 帮助中心）。
 *
 * content 存**已净化**的 HTML（写入前经 HtmlSanitizer::clean），
 * content_text 是同一份内容的纯文本派生列：列表摘要与关键词搜索都读它，
 * 避免每次检索都在 PHP 里 strip 一遍长正文，也避免 LIKE 命中标签名
 * （比如搜 "code" 命中所有 <code> 段落）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_help_docs', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150)->comment('文档标题');
            $table->string('slug', 150)->unique()->comment('稳定标识，深链 /data/help/{slug} 用');
            $table->string('category', 50)->default('通用')->comment('分组：开账/档案/单据/系统…');
            $table->string('summary', 255)->nullable()->comment('列表摘要，留空则取正文纯文本截断');
            $table->longText('content')->nullable()->comment('已净化的 HTML');
            $table->longText('content_text')->nullable()->comment('正文纯文本，供搜索与摘要');
            $table->string('version', 20)->nullable()->comment('文档版本号，如 v1.2');
            $table->unsignedInteger('sort')->default(0);
            $table->string('status', 20)->default('draft')->comment('draft 草稿 / published 已发布');
            $table->boolean('is_top')->default(false)->comment('置顶');
            $table->unsignedInteger('views')->default(0)->comment('阅读量');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name', 60)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('updated_by_name', 60)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('category');
            $table->index('status');
            $table->index('sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_help_docs');
    }
};
