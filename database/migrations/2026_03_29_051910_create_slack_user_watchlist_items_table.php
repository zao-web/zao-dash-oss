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
        Schema::create('slack_user_watchlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('slack_workspaces')->cascadeOnDelete();
            $table->string('slack_user_id');
            $table->foreignId('slack_channel_id')->constrained('slack_channels')->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('source')->default('manual');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'slack_user_id', 'slack_channel_id'], 'slack_watchlist_unique');
            $table->index(['workspace_id', 'slack_user_id', 'is_active'], 'slack_watchlist_user_active_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slack_user_watchlist_items');
    }
};
