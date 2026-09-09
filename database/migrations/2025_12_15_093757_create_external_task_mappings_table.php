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
        Schema::create('external_task_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('external_task_source_id')->constrained()->cascadeOnDelete();
            $table->string('external_id'); // Task ID in external system
            $table->string('external_url')->nullable();
            $table->json('external_data')->nullable(); // Cached external task data
            $table->string('sync_status')->default('synced'); // synced, pending, conflict, error
            $table->string('sync_direction')->default('inbound'); // inbound, outbound, bidirectional
            $table->timestamp('external_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['external_task_source_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_task_mappings');
    }
};
