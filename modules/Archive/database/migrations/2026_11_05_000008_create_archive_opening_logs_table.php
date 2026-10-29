<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 开账操作流水（F6 第二批）。
 *
 * 锁定与反锁定都是动钱的动作，必须留痕：谁、什么时候、干了什么。
 * 只追加不修改，所以只有 created_at。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('archive_opening_logs')) {
            Schema::create('archive_opening_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('wizard_id')->comment('向导ID');
                $table->string('action', 20)->comment('step_done/step_reset/import/lock/unlock');
                $table->unsignedBigInteger('operator_id')->nullable()->comment('操作人ID');
                $table->string('operator_name', 50)->nullable()->comment('操作人姓名');
                $table->string('content', 500)->comment('操作说明');
                $table->timestamp('created_at')->nullable()->comment('操作时间');

                $table->index(['wizard_id', 'action']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_opening_logs');
    }
};
