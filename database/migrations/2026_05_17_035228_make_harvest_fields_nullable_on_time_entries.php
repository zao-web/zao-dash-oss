<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Harvest is being phased out — these columns shouldn't constrain new
        // sources (AI narrative, future native time entry). Existing Harvest-
        // sourced rows keep their values.
        Schema::table('time_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('harvest_id')->nullable()->change();
            $table->unsignedBigInteger('harvest_project_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('harvest_id')->nullable(false)->change();
            $table->unsignedBigInteger('harvest_project_id')->nullable(false)->change();
        });
    }
};
