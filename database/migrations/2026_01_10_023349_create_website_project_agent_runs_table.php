<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_project_agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->string('phase')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->unique(['website_project_id', 'agent_run_id'], 'wp_ar_unique');
            $table->index(['website_project_id', 'phase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_project_agent_runs');
    }
};
