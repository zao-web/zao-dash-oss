<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('x_credential_id')->constrained('x_credentials')->onDelete('cascade');
            $table->string('tweet_id')->index();
            $table->string('author_id');
            $table->string('author_username')->nullable();
            $table->string('author_name')->nullable();
            $table->text('text');
            $table->timestamp('tweet_created_at')->nullable();

            // Entities from tweet
            $table->json('urls')->nullable();
            $table->json('mentions')->nullable();
            $table->json('hashtags')->nullable();
            $table->json('media')->nullable();

            // Public metrics
            $table->unsignedInteger('like_count')->default(0);
            $table->unsignedInteger('retweet_count')->default(0);
            $table->unsignedInteger('reply_count')->default(0);
            $table->unsignedInteger('quote_count')->default(0);

            // Processing status
            $table->string('status')->default('pending'); // pending, analyzed, actionable, pr_created, dismissed
            $table->json('ai_analysis')->nullable(); // AI analysis results
            $table->string('category')->nullable(); // ai_model, prompt_technique, feature_idea, integration, etc.
            $table->unsignedTinyInteger('relevance_score')->nullable(); // 0-100
            $table->text('action_summary')->nullable(); // What action should be taken
            $table->string('pr_branch')->nullable();
            $table->string('pr_url')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamp('pr_created_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();

            $table->timestamps();

            $table->unique(['x_credential_id', 'tweet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('x_bookmarks');
    }
};
