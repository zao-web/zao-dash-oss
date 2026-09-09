<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('self_healing_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('error_signature', 64)->index();
            $table->string('exception_class');
            $table->text('error_message');
            $table->string('source_job')->nullable();
            $table->string('source_file')->nullable();
            $table->integer('source_line')->nullable();
            $table->string('environment')->default('production');
            $table->integer('occurrence_count')->default(1);
            $table->string('nightwatch_url')->nullable();
            $table->string('slack_message_ts');
            $table->string('slack_channel_id');

            $table->enum('status', ['pending', 'in_progress', 'success', 'failed', 'escalated', 'skipped'])
                ->default('pending');
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('commit_sha', 40)->nullable();
            $table->string('commit_url')->nullable();
            $table->string('branch')->nullable();
            $table->text('fix_description')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
            $table->index(['error_signature', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('self_healing_attempts');
    }
};
