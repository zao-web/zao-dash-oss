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
        Schema::create('google_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('expires_at');
            $table->json('scopes');
            $table->string('email')->nullable();
            $table->timestamp('watch_expiration')->nullable();
            $table->string('watch_resource_id')->nullable();
            $table->timestamp('calendar_watch_expiration')->nullable();
            $table->string('calendar_watch_resource_id')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index('watch_expiration');
            $table->index('calendar_watch_expiration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_credentials');
    }
};
