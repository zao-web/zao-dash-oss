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
        Schema::create('website_project_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // File info
            $table->string('filename');
            $table->string('original_filename');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');

            // Asset categorization
            $table->string('type'); // image, document, video, audio, other
            $table->string('category')->nullable(); // logo, hero, background, brief, content, reference, etc.
            $table->string('description')->nullable();

            // Metadata
            $table->json('metadata')->nullable(); // dimensions, duration, page count, etc.

            // Processing status
            $table->string('status')->default('uploaded'); // uploaded, processing, ready, failed
            $table->text('processing_notes')->nullable();

            // WordPress integration (after upload to WP)
            $table->unsignedBigInteger('wordpress_media_id')->nullable();
            $table->string('wordpress_url')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['website_project_id', 'type']);
            $table->index(['website_project_id', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('website_project_assets');
    }
};
