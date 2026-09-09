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
        Schema::create('ad_creative_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_creative_id')->constrained()->cascadeOnDelete();
            $table->string('variant_name')->comment('Variant A, Variant B, etc.');
            $table->boolean('is_control')->default(false);
            $table->enum('status', ['testing', 'winner', 'loser', 'inconclusive'])->default('testing');
            $table->timestamp('test_started_at')->nullable();
            $table->timestamp('test_ended_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('ad_id');
            $table->index('ad_creative_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_creative_variants');
    }
};
