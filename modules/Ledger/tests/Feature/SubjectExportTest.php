<?php

namespace Tests\Ledger\Feature;

use Modules\Ledger\Models\Subject;
use Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class SubjectExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('auth_permission')) {
            Schema::create('auth_permission', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->string('module');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('auth_role_permission')) {
            Schema::create('auth_role_permission', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('permission_id');
                $table->timestamps();
                $table->primary(['role_id', 'permission_id']);
            });
        }

        if (!Schema::hasTable('auth_user')) {
            Schema::create('auth_user', function (Blueprint $table) {
                $table->id();
                $table->string('username')->unique();
                $table->string('password');
                $table->string('real_name')->nullable();
                $table->integer('status')->default(1);
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('auth_role')) {
            Schema::create('auth_role', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->integer('sort')->default(0);
                $table->integer('status')->default(1);
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('auth_user_role')) {
            Schema::create('auth_user_role', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('role_id');
                $table->timestamps();
                $table->primary(['user_id', 'role_id']);
            });
        }

        if (!Schema::hasTable('ledger_subjects')) {
            Schema::create('ledger_subjects', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('parent_id')->default(0);
                $table->string('code')->unique();
                $table->string('name');
                $table->string('category');
                $table->string('balance_direction');
                $table->boolean('is_leaf')->default(true);
                $table->unsignedInteger('level')->default(1);
                $table->json('aux_types')->nullable();
                $table->integer('sort')->default(0);
                $table->string('status')->default('enabled');
                $table->string('remark')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_export_filters_by_name()
    {
        Subject::truncate();

        // Create 2 subjects with parent_id=0 to ensure they are at root of tree()
        Subject::create([
            'parent_id' => 0, 'code' => '1001', 'name' => 'Cash', 'category' => 'asset', 
            'balance_direction' => 'debit', 'level' => 1
        ]);
        Subject::create([
            'parent_id' => 0, 'code' => '2001', 'name' => 'Liability', 'category' => 'liability', 
            'balance_direction' => 'credit', 'level' => 1
        ]);

        $this->actingAsAdmin();

        // Export with name filter = Cash. Should only contain Cash.
        $response = $this->get('/admin/ledger/subjects/export?name=Cash');

        $response->assertStatus(200);
        $content = $response->getContent();
        $lines = explode("\n", trim($content));

        // Expected: Header + 1 matching row = 2 lines.
        // If it ignores filters, it will be Header + 2 rows = 3 lines.
        $this->assertEquals(2, count($lines), 'Export should filter by name. Got ' . count($lines) . ' lines.');
    }
}
