<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spinup_wp_sites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('spinup_id')->unique();
            $table->foreignId('spinup_server_id')->constrained('spinup_wp_servers')->cascadeOnDelete();
            $table->foreignId('wordpress_site_id')->nullable()->constrained('wordpress_sites')->nullOnDelete();
            $table->foreignId('website_project_id')->nullable()->constrained('website_projects')->nullOnDelete();

            $table->string('domain');
            $table->json('additional_domains')->nullable();
            $table->string('site_user');
            $table->string('php_version')->default('8.3');
            $table->string('public_folder')->default('/');
            $table->boolean('is_wordpress')->default(true);

            $table->boolean('page_cache_enabled')->default(true);
            $table->boolean('https_enabled')->default(true);
            $table->json('nginx_config')->nullable();
            $table->json('database_config')->nullable();
            $table->json('backup_config')->nullable();
            $table->json('git_config')->nullable();

            $table->boolean('basic_auth_enabled')->default(false);
            $table->string('basic_auth_username')->nullable();

            $table->string('status')->default('pending');
            $table->unsignedBigInteger('provision_event_id')->nullable();
            $table->timestamp('provisioned_at')->nullable();

            $table->text('wp_admin_user')->nullable();
            $table->text('wp_admin_email')->nullable();
            $table->text('wp_admin_password_encrypted')->nullable();
            $table->text('database_password_encrypted')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('domain');
            $table->index('status');
            $table->index(['spinup_server_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spinup_wp_sites');
    }
};
