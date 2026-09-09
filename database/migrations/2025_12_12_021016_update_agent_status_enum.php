<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            // PostgreSQL: Change column to varchar, update constraint
            // First drop the check constraint
            DB::statement('ALTER TABLE agents DROP CONSTRAINT IF EXISTS agents_status_check');

            // Change column to varchar and update
            DB::statement('ALTER TABLE agents ALTER COLUMN status TYPE varchar(255)');
            DB::statement("ALTER TABLE agents ALTER COLUMN status SET DEFAULT 'paused'");

            // Add new check constraint with updated values
            DB::statement("ALTER TABLE agents ADD CONSTRAINT agents_status_check CHECK (status::text = ANY (ARRAY['active'::text, 'paused'::text, 'disabled'::text, 'circuit_broken'::text]))");

            // Add new columns
            if (! Schema::hasColumn('agents', 'system_prompt')) {
                Schema::table('agents', function (Blueprint $table) {
                    $table->text('system_prompt')->nullable();
                });
            }
            if (! Schema::hasColumn('agents', 'schedule')) {
                Schema::table('agents', function (Blueprint $table) {
                    $table->string('schedule')->nullable();
                });
            }
        } else {
            // SQLite: recreate table approach
            DB::statement('DROP TABLE IF EXISTS agents_backup');
            DB::statement('CREATE TABLE agents_backup AS SELECT * FROM agents');
            Schema::dropIfExists('agents');

            Schema::create('agents', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->enum('status', ['active', 'paused', 'disabled', 'circuit_broken'])->default('paused');
                $table->enum('model', ['opus', 'sonnet', 'haiku'])->default('sonnet');
                $table->boolean('requires_approval')->default(true);
                $table->decimal('max_budget_usd', 8, 2)->default(100.00);
                $table->integer('failure_count')->default(0);
                $table->timestamp('circuit_broken_at')->nullable();
                $table->json('allowed_tools')->nullable();
                $table->text('system_prompt')->nullable();
                $table->string('schedule')->nullable();
                $table->timestamps();
            });

            DB::statement('INSERT INTO agents (id, name, slug, description, status, model, requires_approval, max_budget_usd, failure_count, circuit_broken_at, allowed_tools, system_prompt, schedule, created_at, updated_at) SELECT id, name, slug, description, status, model, requires_approval, max_budget_usd, failure_count, circuit_broken_at, allowed_tools, NULL, NULL, created_at, updated_at FROM agents_backup');
            DB::statement('DROP TABLE agents_backup');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE agents DROP CONSTRAINT IF EXISTS agents_status_check');
            DB::statement("ALTER TABLE agents ALTER COLUMN status SET DEFAULT 'active'");
            DB::statement("ALTER TABLE agents ADD CONSTRAINT agents_status_check CHECK (status::text = ANY (ARRAY['active'::text, 'disabled'::text, 'circuit_broken'::text]))");

            Schema::table('agents', function (Blueprint $table) {
                $table->dropColumn(['system_prompt', 'schedule']);
            });
        } else {
            // SQLite approach
            DB::statement('DROP TABLE IF EXISTS agents_backup');
            DB::statement('CREATE TABLE agents_backup AS SELECT * FROM agents');
            Schema::dropIfExists('agents');

            Schema::create('agents', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->enum('status', ['active', 'disabled', 'circuit_broken'])->default('active');
                $table->enum('model', ['opus', 'sonnet', 'haiku'])->default('sonnet');
                $table->boolean('requires_approval')->default(true);
                $table->decimal('max_budget_usd', 8, 2)->default(100.00);
                $table->integer('failure_count')->default(0);
                $table->timestamp('circuit_broken_at')->nullable();
                $table->json('allowed_tools')->nullable();
                $table->text('system_prompt')->nullable();
                $table->string('schedule')->nullable();
                $table->timestamps();
            });

            DB::statement('INSERT INTO agents (id, name, slug, description, status, model, requires_approval, max_budget_usd, failure_count, circuit_broken_at, allowed_tools, system_prompt, schedule, created_at, updated_at) SELECT id, name, slug, description, status, model, requires_approval, max_budget_usd, failure_count, circuit_broken_at, allowed_tools, system_prompt, schedule, created_at, updated_at FROM agents_backup');
            DB::statement('DROP TABLE agents_backup');
        }
    }
};
