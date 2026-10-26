<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 总账管理 · 记账凭证增加「附件」JSON 列
 *
 * 凭证附件以 JSON 数组列落库（而非独立附件表），
 * 结构对齐前端 `attachment: []` 数组记录格式：
 * 每条记录形如 { name, url, size, type, path, file_name, extension, mime_type }。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_vouchers', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('attachments_count')->comment('附件 JSON 数组，每条含 name/url/size/type/path 等');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_vouchers', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });
    }
};
