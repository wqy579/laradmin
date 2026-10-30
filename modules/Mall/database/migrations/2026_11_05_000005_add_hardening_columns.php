<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F5 交付后加固 —— 订单幂等键 + 短信可观测/重试列。
 *
 *  - mall_orders.idempotency_key：客户端幂等键（唯一），同一键重复下单
 *    返回已建订单，不产生第二条申报单（防重复提交）。
 *  - sms_records.elapsed_ms / request_summary / response_summary：
 *    发送耗时 + 请求/响应摘要，让失败可观测。
 *  - sms_records.retry_count：已重试次数，配合最大重试次数防止无限重发。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mall_orders') && ! Schema::hasColumn('mall_orders', 'idempotency_key')) {
            Schema::table('mall_orders', function (Blueprint $table) {
                $table->string('idempotency_key', 64)->nullable()->after('order_no')
                    ->comment('客户端幂等键(唯一, 重复提交返回已建订单)');
                $table->unique('idempotency_key');
            });
        }

        if (Schema::hasTable('sms_records')) {
            Schema::table('sms_records', function (Blueprint $table) {
                if (! Schema::hasColumn('sms_records', 'elapsed_ms')) {
                    $table->unsignedInteger('elapsed_ms')->default(0)->after('sent_at')
                        ->comment('发送耗时(毫秒)');
                }
                if (! Schema::hasColumn('sms_records', 'request_summary')) {
                    $table->string('request_summary', 500)->nullable()->after('elapsed_ms')
                        ->comment('请求摘要(JSON)');
                }
                if (! Schema::hasColumn('sms_records', 'response_summary')) {
                    $table->string('response_summary', 500)->nullable()->after('request_summary')
                        ->comment('响应摘要(JSON)');
                }
                if (! Schema::hasColumn('sms_records', 'retry_count')) {
                    $table->unsignedInteger('retry_count')->default(0)->after('response_summary')
                        ->comment('已重试次数');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mall_orders') && Schema::hasColumn('mall_orders', 'idempotency_key')) {
            Schema::table('mall_orders', function (Blueprint $table) {
                $table->dropUnique(['idempotency_key']);
                $table->dropColumn('idempotency_key');
            });
        }

        if (Schema::hasTable('sms_records')) {
            Schema::table('sms_records', function (Blueprint $table) {
                foreach (['retry_count', 'response_summary', 'request_summary', 'elapsed_ms'] as $col) {
                    if (Schema::hasColumn('sms_records', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
