<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add email index to client_contacts for faster lookups
        Schema::table('client_contacts', function (Blueprint $table) {
            $table->index('email');
        });

        // Emails from Gmail
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->string('google_message_id')->unique();
            $table->string('thread_id')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_address');
            $table->string('from_name')->nullable();
            $table->json('to_addresses');
            $table->string('subject')->nullable();
            $table->text('body_text')->nullable();
            $table->text('body_html')->nullable();
            $table->timestamp('received_at');
            $table->decimal('sentiment_score', 3, 2)->nullable();
            $table->string('sentiment_label')->nullable();
            $table->boolean('is_transcript')->default(false);
            $table->boolean('is_processed')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('client_id');
            $table->index('received_at');
            $table->index('is_processed');
            $table->index('thread_id');
        });

        // Calendar events
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->string('google_event_id')->unique();
            $table->string('calendar_id')->default('primary');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->boolean('is_all_day')->default(false);
            $table->json('attendees')->nullable();
            $table->string('meet_link')->nullable();
            $table->boolean('is_client_meeting')->default(false);
            $table->boolean('pre_brief_sent')->default(false);
            $table->boolean('post_followup_sent')->default(false);
            $table->string('status')->default('confirmed');
            $table->timestamps();

            $table->index('client_id');
            $table->index('start_at');
            $table->index(['is_client_meeting', 'start_at']);
        });

        // Documents from Drive
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('google_drive_id')->unique();
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->string('document_type')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('extracted_client_name')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('expiration_date')->nullable();
            $table->decimal('contract_value', 12, 2)->nullable();
            $table->text('content_excerpt')->nullable();
            $table->string('web_view_link')->nullable();
            $table->timestamp('google_modified_at')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->index('client_id');
            $table->index('document_type');
            $table->index('expiration_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('emails');

        Schema::table('client_contacts', function (Blueprint $table) {
            $table->dropIndex(['email']);
        });
    }
};
