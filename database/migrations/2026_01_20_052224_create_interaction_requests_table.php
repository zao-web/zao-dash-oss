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
        Schema::create('interaction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('responded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('question_type')->default('text'); // text, select, confirm
            $table->text('question_content');
            $table->json('options')->nullable(); // for select type
            $table->json('context')->nullable(); // additional context for display
            $table->text('response')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('responded_via')->nullable(); // dashboard, slack, mcp
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamps();

            // Index for finding pending interactions by run
            $table->index(['agent_run_id', 'responded_at']);
            // Index for cleanup job to find expired interactions
            $table->index(['expires_at', 'responded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interaction_requests');
    }
};
