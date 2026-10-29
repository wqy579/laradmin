<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 开账向导步骤表（F6 第二批）。
 *
 * 四步各自一行，前端步骤条直接按本表渲染（不是前端硬编码进度）：这样换主体、
 * 换期间都能看到真实状态，也便于第 4 步汇总时判断前序是否真的做完。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('archive_opening_steps')) {
            Schema::create('archive_opening_steps', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('wizard_id')->comment('向导ID');
                $table->unsignedTinyInteger('step')->comment('步骤序号 1-4');
                $table->string('key', 20)->comment('步骤标识 subject/stock/balance/import');
                $table->string('title', 50)->comment('步骤标题');
                $table->string('status', 20)->default('pending')->comment('pending待处理/done已完成/skipped已跳过');
                $table->timestamp('done_at')->nullable()->comment('完成时间');
                $table->unsignedBigInteger('done_by')->nullable()->comment('完成人ID');
                $table->string('done_by_name', 50)->nullable()->comment('完成人姓名');
                $table->json('detail')->nullable()->comment('完成时的统计明细，如科目数/库存行数/试算结果');
                $table->timestamps();

                $table->unique(['wizard_id', 'step']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_opening_steps');
    }
};
