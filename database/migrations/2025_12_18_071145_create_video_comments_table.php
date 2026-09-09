<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('video_comments')->cascadeOnDelete();
            $table->text('content');
            $table->integer('timestamp_seconds')->nullable();
            $table->string('timestamp_formatted', 10)->nullable();
            $table->string('viewer_name')->nullable();
            $table->string('viewer_email')->nullable();
            $table->string('type', 20)->default('comment');
            $table->boolean('is_approved')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['video_id', 'is_approved']);
            $table->index(['video_id', 'timestamp_seconds']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_comments');
    }
};
