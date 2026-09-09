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
        Schema::create('external_task_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pm_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id'); // List ID (ClickUp), Database ID (Notion)
            $table->string('name');
            $table->string('type'); // 'list', 'folder', 'space', 'database'
            $table->json('field_mappings')->nullable(); // Map external fields → Task fields
            $table->json('sync_filters')->nullable(); // Only sync tasks matching filter
            $table->json('status_mappings')->nullable(); // Map external statuses → internal
            $table->boolean('auto_import')->default(true);
            $table->boolean('sync_back')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['pm_connection_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_task_sources');
    }
};
