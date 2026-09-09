<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_chains', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->json('steps'); // Array of {agent_slug, condition, transform}
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('agent_chain_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_chain_id')->constrained()->onDelete('cascade');
            $table->string('status')->default('pending'); // pending, running, completed, failed, cancelled
            $table->integer('current_step')->default(0);
            $table->text('initial_input')->nullable();
            $table->json('step_results')->nullable(); // Results from each step
            $table->decimal('total_cost_usd', 10, 6)->default(0);
            $table->text('error_message')->nullable();
            $table->string('triggered_by')->nullable(); // manual, schedule, webhook, agent
            $table->json('trigger_metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['agent_chain_id', 'status']);
        });

        // Add chain_run_id to agent_runs to link individual runs to a chain
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->foreignId('chain_run_id')->nullable()->after('agent_id')
                ->constrained('agent_chain_runs')->nullOnDelete();
            $table->integer('chain_step_index')->nullable()->after('chain_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chain_run_id');
            $table->dropColumn('chain_step_index');
        });

        Schema::dropIfExists('agent_chain_runs');
        Schema::dropIfExists('agent_chains');
    }
};
