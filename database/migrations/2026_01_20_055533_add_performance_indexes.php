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
        Schema::table('tasks', function (Blueprint $table) {
            $table->index('status');
            $table->index('project_id');
            $table->index(['project_id', 'status']);
        });

        Schema::table('seo_pages', function (Blueprint $table) {
            $table->index('playbook');
            $table->index(['status', 'playbook']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['project_id']);
            $table->dropIndex(['project_id', 'status']);
        });

        Schema::table('seo_pages', function (Blueprint $table) {
            $table->dropIndex(['playbook']);
            $table->dropIndex(['status', 'playbook']);
        });
    }
};
