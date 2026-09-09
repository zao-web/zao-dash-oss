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
        Schema::create('website_project_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role')->default('user'); // user, assistant, system
            $table->text('content');
            $table->json('metadata')->nullable(); // phase, tool, action, progress, etc.
            $table->string('status')->default('sent'); // sending, sent, error
            $table->timestamps();

            $table->index(['website_project_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('website_project_messages');
    }
};
