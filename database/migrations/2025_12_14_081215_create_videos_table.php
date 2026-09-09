<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();

            // Video metadata
            $table->string('title');
            $table->string('folder_path')->nullable(); // e.g., "Acme Corp/Website Redesign"
            $table->text('description')->nullable();
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('storage_disk')->default('local'); // local, s3, r2
            $table->unsignedInteger('duration')->nullable(); // seconds
            $table->unsignedBigInteger('file_size'); // bytes
            $table->string('mime_type');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // Thumbnails
            $table->string('thumbnail_path')->nullable();

            // Sharing
            $table->string('share_token', 32)->unique();
            $table->timestamp('share_expires_at')->nullable();
            $table->boolean('is_public')->default(true);
            $table->string('password_hash')->nullable(); // optional password protection

            // Processing status
            $table->string('status')->default('processing'); // uploading, processing, ready, failed
            $table->text('processing_error')->nullable();

            // Stats (denormalized for performance)
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('unique_view_count')->default(0);

            // Recording metadata
            $table->json('recording_metadata')->nullable(); // resolution, fps, recording_type, etc

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
            $table->index(['project_id']);
            $table->index(['client_id']);
            $table->index(['task_id']);
            $table->index(['folder_path']);
            $table->index(['status']);
        });

        Schema::create('video_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();

            // Viewer identification
            $table->string('viewer_email')->nullable();
            $table->string('viewer_ip', 45); // supports IPv6
            $table->string('viewer_fingerprint')->nullable(); // browser fingerprint for unique tracking
            $table->text('user_agent')->nullable();

            // Watch metrics
            $table->unsignedInteger('watch_duration')->default(0); // seconds watched
            $table->unsignedSmallInteger('watch_percentage')->default(0); // 0-100
            $table->boolean('completed')->default(false); // watched >= 90%

            // Context
            $table->string('referrer')->nullable();
            $table->string('country', 2)->nullable(); // ISO country code
            $table->string('device_type')->nullable(); // desktop, mobile, tablet

            $table->timestamp('started_at');
            $table->timestamp('last_ping_at')->nullable();

            $table->index(['video_id', 'started_at']);
            $table->index(['viewer_email']);
            $table->index(['viewer_ip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_views');
        Schema::dropIfExists('videos');
    }
};
