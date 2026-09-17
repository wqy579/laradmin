<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * returns 表建表时漏了审批审计字段，但 ReturnOrder 的 fillable 与
     * ReturnController::approve() 都在写 approved_by / approved_at——
     * 结果是退货单一审批就 SQL 报错 500。补上这两列。
     */
    public function up(): void
    {
        if (Schema::hasColumn('returns', 'approved_by') && Schema::hasColumn('returns', 'approved_at')) {
            return;
        }

        Schema::table('returns', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('created_by')
                ->constrained('auth_user')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('returns', 'approved_by') && ! Schema::hasColumn('returns', 'approved_at')) {
            return;
        }

        $columns = array_values(array_filter(
            ['approved_by', 'approved_at'],
            fn (string $c) => Schema::hasColumn('returns', $c)
        ));

        Schema::table('returns', function (Blueprint $table) use ($columns) {
            if (in_array('approved_by', $columns, true)) {
                $table->dropForeign(['approved_by']);
            }
            $table->dropColumn($columns);
        });
    }
};
