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
            // PostgreSQL: Modify CHECK constraint
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role::text = ANY (ARRAY['owner'::text, 'admin'::text, 'staff'::text, 'client'::text]))");
        } else {
            // SQLite: Recreate table with new enum values
            // Disable foreign keys during table recreation
            DB::statement('PRAGMA foreign_keys = OFF');

            DB::statement('DROP TABLE IF EXISTS users_backup');
            DB::statement('CREATE TABLE users_backup AS SELECT * FROM users');

            $columns = Schema::getColumnListing('users');
            Schema::dropIfExists('users');

            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->enum('role', ['owner', 'admin', 'staff', 'client'])->default('staff');
                $table->string('avatar')->nullable();
                $table->string('phone')->nullable();
                $table->string('title')->nullable();
                $table->string('department')->nullable();
                $table->decimal('hourly_rate', 8, 2)->nullable();
                $table->decimal('target_hours_weekly', 5, 2)->nullable();
                $table->rememberToken();
                $table->timestamps();
            });

            // Restore data
            $selectColumns = array_intersect($columns, [
                'id', 'name', 'email', 'email_verified_at', 'password', 'role',
                'avatar', 'phone', 'title', 'department', 'hourly_rate',
                'target_hours_weekly', 'remember_token', 'created_at', 'updated_at',
            ]);
            $columnList = implode(', ', $selectColumns);
            DB::statement("INSERT INTO users ({$columnList}) SELECT {$columnList} FROM users_backup");
            DB::statement('DROP TABLE users_backup');

            // Re-enable foreign keys
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            // Remove 'admin' from allowed values
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role::text = ANY (ARRAY['owner'::text, 'staff'::text, 'client'::text]))");
        } else {
            // SQLite: Recreate without admin
            // Disable foreign keys during table recreation
            DB::statement('PRAGMA foreign_keys = OFF');

            DB::statement('DROP TABLE IF EXISTS users_backup');
            DB::statement('CREATE TABLE users_backup AS SELECT * FROM users');
            Schema::dropIfExists('users');

            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->enum('role', ['owner', 'staff', 'client'])->default('staff');
                $table->string('avatar')->nullable();
                $table->string('phone')->nullable();
                $table->string('title')->nullable();
                $table->string('department')->nullable();
                $table->decimal('hourly_rate', 8, 2)->nullable();
                $table->decimal('target_hours_weekly', 5, 2)->nullable();
                $table->rememberToken();
                $table->timestamps();
            });

            DB::statement('INSERT INTO users (id, name, email, email_verified_at, password, role, avatar, phone, title, department, hourly_rate, target_hours_weekly, remember_token, created_at, updated_at) SELECT id, name, email, email_verified_at, password, role, avatar, phone, title, department, hourly_rate, target_hours_weekly, remember_token, created_at, updated_at FROM users_backup');
            DB::statement('DROP TABLE users_backup');

            // Re-enable foreign keys
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
