<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linked_in_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('linkedin_id')->unique();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('profile_url')->nullable();
            $table->string('profile_picture')->nullable();
            $table->string('headline')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('organization_id')->nullable();
            $table->string('organization_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });

        Schema::create('x_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('x_user_id')->unique();
            $table->string('username');
            $table->string('name')->nullable();
            $table->string('profile_image_url')->nullable();
            $table->text('description')->nullable();
            $table->boolean('verified')->default(false);
            $table->unsignedInteger('followers_count')->default(0);
            $table->unsignedInteger('following_count')->default(0);
            $table->unsignedInteger('tweet_count')->default(0);
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });

        // Social posts tracking
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('platform'); // linkedin, x
            $table->string('platform_post_id')->nullable();
            $table->text('content');
            $table->string('url')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('draft'); // draft, scheduled, published, failed
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('metrics')->nullable(); // likes, shares, comments, etc.
            $table->json('metadata')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'platform', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('x_credentials');
        Schema::dropIfExists('linked_in_credentials');
    }
};
