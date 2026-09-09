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
        Schema::create('video_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('storage_path');
            $table->string('storage_disk')->default('local');
            $table->unsignedInteger('duration')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->decimal('trim_start_seconds', 10, 3)->nullable();
            $table->decimal('trim_end_seconds', 10, 3)->nullable();
            $table->string('status', 20)->default('pending'); // pending, processing, ready, failed
            $table->boolean('is_current')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['video_id', 'version_number']);
            $table->index(['video_id', 'is_current']);
            $table->index(['video_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_versions');
    }
};
