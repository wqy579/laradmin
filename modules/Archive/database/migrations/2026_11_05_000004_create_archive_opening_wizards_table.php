<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 开账向导主表（F6 第二批）。
 *
 * 连凯现场新主体上线时要按「科目 → 期初库存 → 期初余额 → 导入/确认」四步把老账
 * 套进系统，套完锁定，之后不允许再从本向导改期初。本表就是这张向导的头部：
 * 一个「公司 + 事业部（可空）+ 期初期间」组合对应一份向导。
 *
 * 注意：(company_id, division_id, period) 的业务唯一性**没有**建成数据库唯一索引。
 * division_id 可空，而 MySQL 与 sqlite 的唯一索引都不约束 NULL（同公司同期间、
 * division_id 同为 NULL 的多行可以同时落库），建成唯一索引等于没建，还会让人误以为
 * 有约束兜底。唯一性改由 OpeningWizardService::show() 写入前先查一次来保证。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('archive_opening_wizards')) {
            Schema::create('archive_opening_wizards', function (Blueprint $table) {
                $table->id();
                // 两张档案表由第一批迁移（...001/002）建，时序在本表之前，可以建真外键；
                // 主体被开账引用后禁止删除（restrict），事业部停用则置空保留开账记录
                $table->foreignId('company_id')->constrained('archive_companies')->restrictOnDelete();
                $table->foreignId('division_id')->nullable()->constrained('archive_divisions')->nullOnDelete();
                $table->string('period', 7)->comment('期初期间，如 2026-01');
                $table->string('status', 20)->default('draft')->comment('draft草稿/locked已开账锁定');
                $table->unsignedTinyInteger('step')->default(1)->comment('当前步骤 1-4');
                $table->timestamp('locked_at')->nullable()->comment('锁定时间');
                $table->unsignedBigInteger('locked_by')->nullable()->comment('锁定的操作员ID');
                $table->string('locked_by_name', 50)->nullable()->comment('锁定的操作员姓名');
                $table->json('lock_report')->nullable()->comment('锁定回写结果统计');
                $table->string('remark', 255)->nullable()->comment('备注');
                $table->softDeletes();
                $table->timestamps();

                // 唯一性见类注释：有意不做 DB 唯一索引，由服务层保证
                $table->index(['company_id', 'division_id', 'period']);
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_opening_wizards');
    }
};
