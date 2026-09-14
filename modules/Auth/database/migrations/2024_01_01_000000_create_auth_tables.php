<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 管理员表
        Schema::create('auth_user', function (Blueprint $table) {
            $table->id();
            $table->string('username', 50)->unique()->comment('用户名');
            $table->string('password')->comment('密码');
            $table->string('real_name', 50)->comment('真实姓名');
            $table->string('email', 100)->nullable()->comment('邮箱');
            $table->string('phone', 20)->nullable()->comment('手机号');
            $table->unsignedBigInteger('department_id')->nullable()->comment('部门ID');
            $table->string('avatar')->nullable()->comment('头像');
            $table->tinyInteger('status')->default(1)->comment('状态：1启用 0禁用');
            $table->timestamp('last_login_at')->nullable()->comment('最后登录时间');
            $table->string('last_login_ip', 50)->nullable()->comment('最后登录IP');
            $table->softDeletes();
            $table->timestamps();

            $table->index('department_id');
            $table->index('status');
            $table->index('email', 'idx_auth_user_email');
            $table->index('phone', 'idx_auth_user_phone');
            $table->index('last_login_at', 'idx_auth_user_last_login_at');
            $table->index(['status', 'department_id'], 'idx_auth_user_status_department');
            $table->index('created_at', 'idx_auth_user_created_at');
        });

        // 部门表
        Schema::create('auth_department', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('部门名称');
            $table->unsignedBigInteger('parent_id')->default(0)->comment('父部门ID');
            $table->string('leader', 50)->nullable()->comment('部门负责人');
            $table->string('phone', 20)->nullable()->comment('联系电话');
            $table->integer('sort')->default(0)->comment('排序');
            $table->tinyInteger('status')->default(1)->comment('状态：1启用 0禁用');
            $table->softDeletes();
            $table->timestamps();

            $table->index('parent_id');
            $table->index('status');
            $table->index(['parent_id', 'status'], 'idx_auth_department_parent_status');
            $table->index('sort', 'idx_auth_department_sort');
        });

        // 角色表
        Schema::create('auth_role', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique()->comment('角色名称');
            $table->string('code', 50)->unique()->comment('角色编码');
            $table->text('description')->nullable()->comment('角色描述');
            $table->integer('sort')->default(0)->comment('排序');
            $table->tinyInteger('status')->default(1)->comment('状态：1启用 0禁用');
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index(['status', 'sort'], 'idx_auth_role_status_sort');
        });

        // 权限表
        Schema::create('auth_permission', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100)->comment('权限标题');
            $table->string('name', 100)->unique()->comment('权限编码');
            $table->string('type', 20)->default('menu')->comment('类型：api button menu url');
            $table->unsignedBigInteger('parent_id')->default(0)->comment('父级ID');
            $table->string('path')->nullable()->comment('路由路径');
            $table->string('component')->nullable()->comment('前端组件路径');
            $table->json('meta')->nullable()->comment('元数据（隐藏菜单、面包屑等）');
            $table->integer('sort')->default(0)->comment('排序');
            $table->tinyInteger('status')->default(1)->comment('状态：1启用 0禁用');
            $table->softDeletes();
            $table->timestamps();

            $table->index('parent_id');
            $table->index('type');
            $table->index('status');
            $table->index('name');
            $table->index(['parent_id', 'status', 'sort'], 'idx_auth_permission_parent_status_sort');
            $table->index(['type', 'status'], 'idx_auth_permission_type_status');
        });

        // 用户角色关联表
        Schema::create('auth_user_role', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('用户ID');
            $table->unsignedBigInteger('role_id')->comment('角色ID');
            $table->timestamps();

            $table->unique(['user_id', 'role_id']);
            $table->index('user_id');
            $table->index('role_id');
        });

        // 角色权限关联表
        Schema::create('auth_role_permission', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id')->comment('角色ID');
            $table->unsignedBigInteger('permission_id')->comment('权限ID');
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
            $table->index('role_id');
            $table->index('permission_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auth_role_permission');
        Schema::dropIfExists('auth_user_role');
        Schema::dropIfExists('auth_permission');
        Schema::dropIfExists('auth_role');
        Schema::dropIfExists('auth_department');
        Schema::dropIfExists('auth_user');
    }
};
