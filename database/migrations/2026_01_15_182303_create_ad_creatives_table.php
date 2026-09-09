<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ad_creatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_ad_account_id')->constrained()->cascadeOnDelete();
            $table->string('creative_id')->unique()->nullable()->comment('Meta creative ID');
            $table->string('name');
            $table->enum('type', ['image', 'carousel', 'video'])->default('image');
            $table->string('headline');
            $table->text('primary_text');
            $table->string('description')->nullable();
            $table->text('image_url')->nullable()->comment('S3/CDN URL');
            $table->string('image_hash')->nullable()->comment('Meta image hash');
            $table->json('carousel_items')->nullable()->comment('[{image_url, headline, description, link}]');
            $table->foreignId('brand_guideline_id')->nullable()->constrained()->nullOnDelete();
            $table->text('generation_prompt')->nullable();
            $table->json('generation_metadata')->nullable()->comment('Model used, cost, etc.');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('meta_ad_account_id');
            $table->index('brand_guideline_id');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_creatives');
    }
};
