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
        Schema::create('ab_test_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('test_type', ['creative', 'audience', 'placement', 'budget']);
            $table->enum('status', ['running', 'completed', 'stopped'])->default('running');
            $table->decimal('confidence_level', 5, 4)->nullable()->comment('Chi-square p-value');
            $table->unsignedBigInteger('winner_id')->nullable()->comment('FK to winning variant');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->json('results')->nullable()->comment('{control: {ctr, cpa}, variant: {ctr, cpa}, lift: 0.15}');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('ad_campaign_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ab_test_groups');
    }
};
