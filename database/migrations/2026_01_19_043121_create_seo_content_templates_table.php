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
        Schema::create('seo_content_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_name')->unique();
            $table->string('template_type'); // service_page, comparison, how_to, category_hub, location_page
            $table->text('prompt_template');
            $table->json('variables')->nullable(); // Available template variables
            $table->decimal('avg_conversion_rate', 5, 2)->default(0);
            $table->unsignedInteger('pages_generated')->default(0);
            $table->unsignedInteger('total_leads')->default(0);
            $table->string('status')->default('active'); // active, testing, archived
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_content_templates');
    }
};
