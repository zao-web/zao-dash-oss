<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slack_thread_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('slack_channels')->cascadeOnDelete();
            $table->string('thread_ts');
            $table->string('bot_user_id');
            $table->json('conversation_history')->nullable();
            $table->json('extracted_intents')->nullable();
            $table->json('pending_actions')->nullable();
            $table->json('completed_actions')->nullable();
            $table->json('context_data')->nullable();
            $table->string('current_state')->default('idle');
            $table->foreignId('agent_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'thread_ts']);
            $table->index('current_state');
            $table->index('last_interaction_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slack_thread_contexts');
    }
};
