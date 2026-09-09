<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Slack workspaces (each OAuth'd workspace)
        Schema::create('slack_workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('workspace_id')->unique();
            $table->string('workspace_name');
            $table->text('access_token');
            $table->string('bot_user_id')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index('is_primary');
        });

        // Slack channels being monitored
        Schema::create('slack_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('slack_workspaces')->cascadeOnDelete();
            $table->string('channel_id');
            $table->string('channel_name');
            $table->boolean('is_private')->default(false);
            $table->boolean('is_shared')->default(false);
            $table->string('classification')->default('general');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('monitoring_enabled')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'channel_id']);
            $table->index('client_id');
            $table->index('monitoring_enabled');
        });

        // Slack messages
        Schema::create('slack_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('slack_workspaces')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('slack_channels')->cascadeOnDelete();
            $table->string('message_ts');
            $table->string('thread_ts')->nullable();
            $table->string('user_id');
            $table->string('user_name')->nullable();
            $table->boolean('user_is_external')->default(false);
            $table->text('content');
            $table->json('attachments')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('has_action_item')->default(false);
            $table->text('action_item_extracted')->nullable();
            $table->decimal('action_item_confidence', 3, 2)->nullable();
            $table->boolean('is_repeated_request')->default(false);
            $table->integer('repeated_request_count')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'message_ts']);
            $table->index('thread_ts');
            $table->index('client_id');
            $table->index('has_action_item');
            $table->index('created_at');
        });

        // Slack threads (for summarization)
        Schema::create('slack_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('slack_channels')->cascadeOnDelete();
            $table->string('thread_ts');
            $table->integer('message_count')->default(0);
            $table->json('participants')->nullable();
            $table->boolean('has_external_participant')->default(false);
            $table->text('summary')->nullable();
            $table->json('action_items_extracted')->nullable();
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'thread_ts']);
            $table->index('has_external_participant');
        });

        // Repeated request patterns (sentiment decay tracking)
        Schema::create('slack_request_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->json('topic_embedding')->nullable(); // Vector stored as JSON array
            $table->string('topic_summary');
            $table->timestamp('first_asked_at');
            $table->timestamp('last_asked_at');
            $table->integer('ask_count')->default(1);
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->json('messages')->nullable();
            $table->timestamps();

            $table->index('client_id');
            $table->index('is_resolved');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slack_request_patterns');
        Schema::dropIfExists('slack_threads');
        Schema::dropIfExists('slack_messages');
        Schema::dropIfExists('slack_channels');
        Schema::dropIfExists('slack_workspaces');
    }
};
