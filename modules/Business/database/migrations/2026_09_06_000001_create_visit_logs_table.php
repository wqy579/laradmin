<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('visit_no', 30)->unique()->comment('拜访单号');
            $table->unsignedBigInteger('employee_id')->comment('员工ID');
            $table->unsignedBigInteger('customer_id')->comment('客户ID');
            $table->unsignedBigInteger('route_id')->nullable()->comment('线路ID');
            $table->datetime('checkin_time')->comment('签到时间');
            $table->decimal('checkin_lat', 10, 7)->nullable()->comment('签到纬度');
            $table->decimal('checkin_lng', 10, 7)->nullable()->comment('签到经度');
            $table->string('checkin_address', 255)->nullable()->comment('签到地址');
            $table->string('checkin_photo', 255)->nullable()->comment('签到照片');
            $table->datetime('checkout_time')->nullable()->comment('签退时间');
            $table->decimal('checkout_lat', 10, 7)->nullable()->comment('签退纬度');
            $table->decimal('checkout_lng', 10, 7)->nullable()->comment('签退经度');
            $table->string('checkout_photo', 255)->nullable()->comment('签退照片');
            $table->integer('visit_duration')->nullable()->comment('拜访时长（分钟）');
            $table->string('visit_result', 50)->nullable()->comment('拜访结果');
            $table->text('remark')->nullable()->comment('备注');
            $table->tinyInteger('status')->default(1)->comment('状态：1待拜访 2已拜访');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人');
            $table->timestamps();

            $table->index('employee_id');
            $table->index('customer_id');
            $table->index('route_id');
            $table->index('checkin_time');
            $table->index('status');
            $table->index(['employee_id', 'checkin_time'], 'idx_employee_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_logs');
    }
};
