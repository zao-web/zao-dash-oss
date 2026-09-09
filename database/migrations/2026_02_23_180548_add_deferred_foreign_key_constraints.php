<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreign('client_id')
                ->references('id')
                ->on('clients')
                ->onDelete('cascade');
        });

        Schema::table('site_builder_projects', function (Blueprint $table) {
            $table->foreign('wordpress_site_id')
                ->references('id')
                ->on('wordpress_sites')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
        });

        Schema::table('site_builder_projects', function (Blueprint $table) {
            $table->dropForeign(['wordpress_site_id']);
        });
    }
};
