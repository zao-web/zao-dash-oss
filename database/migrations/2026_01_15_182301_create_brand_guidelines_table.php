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
        Schema::create('brand_guidelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('primary_colors')->comment('[#hex, #hex]');
            $table->json('secondary_colors')->nullable();
            $table->json('fonts')->nullable()->comment('{primary: "Inter", secondary: "Merriweather"}');
            $table->text('logo_url')->nullable();
            $table->text('brand_voice')->nullable()->comment('Professional, technical, approachable');
            $table->text('tone')->nullable()->comment('Confident but not salesy');
            $table->json('keywords_to_include')->nullable()->comment('["workflow", "automation"]');
            $table->json('keywords_to_avoid')->nullable()->comment('["cheap", "easy", "simple"]');
            $table->text('imagery_style')->nullable()->comment('Clean, modern, minimal');
            $table->json('competitor_urls')->nullable();
            $table->timestamps();

            $table->index('client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_guidelines');
    }
};
