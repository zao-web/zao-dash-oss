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
        Schema::create('spinup_wp_servers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('spinup_id')->unique()->comment('SpinupWP server ID');
            $table->string('name');
            $table->string('provider_name')->nullable();
            $table->string('ubuntu_version')->nullable();
            $table->string('ip_address')->nullable();
            $table->unsignedInteger('ssh_port')->default(22);
            $table->string('timezone')->default('UTC');
            $table->string('region')->nullable();
            $table->string('size')->nullable();
            $table->json('disk_space')->nullable();
            $table->json('database_config')->nullable();
            $table->text('ssh_publickey')->nullable();
            $table->text('git_publickey')->nullable();
            $table->string('connection_status')->default('unknown');
            $table->boolean('reboot_required')->default(false);
            $table->boolean('upgrade_required')->default(false);
            $table->text('install_notes')->nullable();
            $table->string('status')->default('unknown');
            $table->boolean('is_default')->default(false)->comment('Use as default for new sites');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('is_default');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spinup_wp_servers');
    }
};
