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
        Schema::create('seo_keywords', function (Blueprint $table) {
            $table->id();
            $table->string('keyword')->unique();
            $table->string('intent')->nullable(); // informational, transactional, navigational
            $table->unsignedInteger('estimated_monthly_volume')->nullable();
            $table->unsignedInteger('difficulty_score')->nullable();
            $table->string('source')->nullable(); // google_keyword_planner, manual, competitor
            $table->decimal('current_position', 5, 2)->nullable();
            $table->decimal('best_position', 5, 2)->nullable();
            $table->foreignId('seo_page_id')->nullable()->constrained()->onDelete('set null');
            $table->string('status')->default('active'); // active, targeting, ranked, archived
            $table->timestamp('first_tracked_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            // Indexes for opportunity queries
            $table->index(['intent', 'difficulty_score', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_keywords');
    }
};
