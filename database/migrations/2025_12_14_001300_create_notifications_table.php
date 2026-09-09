<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // approval_needed, sync_complete, agent_run, system, etc.
            $table->string('title');
            $table->text('message');
            $table->string('icon')->nullable(); // Emoji or icon class
            $table->string('severity')->default('info'); // info, success, warning, error
            $table->string('action_url')->nullable(); // Link to click
            $table->string('action_label')->nullable(); // Button text
            $table->json('metadata')->nullable(); // Extra data
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
