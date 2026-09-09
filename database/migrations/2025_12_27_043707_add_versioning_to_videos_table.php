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
        Schema::table('videos', function (Blueprint $table) {
            $table->boolean('has_versions')->default(false)->after('recording_metadata');
            $table->unsignedInteger('current_version')->default(1)->after('has_versions');
            $table->string('original_storage_path')->nullable()->after('current_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['has_versions', 'current_version', 'original_storage_path']);
        });
    }
};
