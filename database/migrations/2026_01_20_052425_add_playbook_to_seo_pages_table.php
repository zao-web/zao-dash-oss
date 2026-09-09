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
        Schema::table('seo_pages', function (Blueprint $table) {
            // Playbook column for SEO content strategy categorization
            if (! Schema::hasColumn('seo_pages', 'playbook')) {
                $table->string('playbook')->nullable()->after('page_type')
                    ->comment('SEO playbook: Location, Comparisons, Persona, Vertical, Integration, Tools, etc.');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seo_pages', function (Blueprint $table) {
            if (Schema::hasColumn('seo_pages', 'playbook')) {
                $table->dropColumn('playbook');
            }
        });
    }
};
