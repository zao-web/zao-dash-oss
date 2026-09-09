<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_projects', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->unique(['user_id', 'slug'], 'website_projects_user_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('website_projects', function (Blueprint $table) {
            $table->dropUnique('website_projects_user_slug_unique');
            $table->unique('slug');
        });
    }
};
