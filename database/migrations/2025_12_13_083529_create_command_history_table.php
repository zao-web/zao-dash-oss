<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('command_id'); // e.g., 'nav-dashboard', 'agent-meeting-parser'
            $table->string('command_type'); // navigation, action, agent, ai
            $table->string('query')->nullable(); // Original search query
            $table->json('metadata')->nullable(); // Additional context
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->index(['user_id', 'executed_at']);
            $table->index(['user_id', 'command_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_history');
    }
};
