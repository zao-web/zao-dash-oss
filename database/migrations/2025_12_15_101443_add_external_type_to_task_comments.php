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
            // PostgreSQL: Just update the CHECK constraint and make user_id nullable
            DB::statement('ALTER TABLE task_comments DROP CONSTRAINT IF EXISTS task_comments_type_check');
            DB::statement("ALTER TABLE task_comments ADD CONSTRAINT task_comments_type_check CHECK (type::text = ANY (ARRAY['comment'::text, 'status_change'::text, 'assignment'::text, 'system'::text, 'external'::text]))");
            DB::statement('ALTER TABLE task_comments ALTER COLUMN user_id DROP NOT NULL');
        } else {
            // SQLite: Recreate the table
            Schema::create('task_comments_new', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
                $table->enum('type', ['comment', 'status_change', 'assignment', 'system', 'external'])->default('comment');
                $table->text('content');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['task_id', 'created_at']);
            });

            DB::statement('INSERT INTO task_comments_new SELECT * FROM task_comments');
            Schema::dropIfExists('task_comments');
            Schema::rename('task_comments_new', 'task_comments');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            // PostgreSQL: Revert CHECK constraint and make user_id required again
            DB::statement("DELETE FROM task_comments WHERE type = 'external'");
            DB::statement('ALTER TABLE task_comments DROP CONSTRAINT IF EXISTS task_comments_type_check');
            DB::statement("ALTER TABLE task_comments ADD CONSTRAINT task_comments_type_check CHECK (type::text = ANY (ARRAY['comment'::text, 'status_change'::text, 'assignment'::text, 'system'::text]))");
            DB::statement('ALTER TABLE task_comments ALTER COLUMN user_id SET NOT NULL');
        } else {
            // SQLite: Recreate table
            Schema::create('task_comments_old', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->enum('type', ['comment', 'status_change', 'assignment', 'system'])->default('comment');
                $table->text('content');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['task_id', 'created_at']);
            });

            DB::statement("INSERT INTO task_comments_old SELECT * FROM task_comments WHERE type != 'external'");
            Schema::dropIfExists('task_comments');
            Schema::rename('task_comments_old', 'task_comments');
        }
    }
};
