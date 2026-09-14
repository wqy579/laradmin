<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 员工档案表
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique()->comment('员工编号');
            $table->string('name', 50)->comment('员工姓名');
            $table->string('gender', 10)->nullable()->comment('性别');
            $table->string('department', 50)->nullable()->comment('部门');
            $table->string('position', 50)->nullable()->comment('职位');
            $table->string('salesman_code', 30)->nullable()->comment('业务员编码');
            $table->string('phone', 20)->nullable()->comment('联系电话');
            $table->boolean('wechat_bound')->default(false)->comment('是否绑定微信');
            $table->string('id_card', 20)->nullable()->comment('身份证号');
            $table->date('hire_date')->nullable()->comment('入职日期');
            $table->decimal('base_salary', 10, 2)->default(0)->comment('基本工资');
            $table->decimal('commission_rate', 5, 2)->default(0)->comment('提成比例(%)');
            $table->unsignedBigInteger('user_id')->nullable()->comment('关联系统用户ID');
            $table->string('role', 30)->default('staff')->comment('角色:staff/salesman/manager/admin');
            $table->boolean('is_active')->default(true)->comment('是否在职');
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->index('code');
            $table->index('role');
            $table->index('user_id');
        });

        // 考勤记录表
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('date')->comment('考勤日期');
            $table->datetime('clock_in')->nullable()->comment('签到时间');
            $table->decimal('clock_in_lat', 10, 7)->nullable()->comment('签到纬度');
            $table->decimal('clock_in_lng', 10, 7)->nullable()->comment('签到经度');
            $table->string('clock_in_address', 255)->nullable()->comment('签到地址');
            $table->datetime('clock_out')->nullable()->comment('签退时间');
            $table->decimal('clock_out_lat', 10, 7)->nullable()->comment('签退纬度');
            $table->decimal('clock_out_lng', 10, 7)->nullable()->comment('签退经度');
            $table->tinyInteger('status')->default(1)->comment('状态:1正常 2迟到 3早退 4缺勤 5请假');
            $table->integer('visit_count')->default(0)->comment('当日拜访客户数');
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('employees');
    }
};
