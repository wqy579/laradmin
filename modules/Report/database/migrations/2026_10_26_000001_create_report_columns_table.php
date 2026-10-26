<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 报表列配置持久化（E1 基座）：每张报表每个用户一份列显隐 / 排序偏好。
 *
 * 用户在某报表点「列设置」勾选显隐、拖拽排序后，把列配置快照存到这里，
 * 下次打开该报表按同一份列顺序 / 显隐渲染。按 report + user_id 唯一，
 * 只写报表专属表，不碰任何业务表。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_columns')) {
            Schema::create('report_columns', function (Blueprint $table) {
                $table->id();
                $table->string('report', 50)->comment('归属报表：sales/stock/salesman/combined/…');
                $table->unsignedBigInteger('user_id')->comment('归属用户');
                $table->json('columns')->comment('列配置快照：[{prop,label,visible},…] 按展示顺序');
                $table->timestamps();

                $table->unique(['report', 'user_id']);
                $table->index('report');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_columns');
    }
};
