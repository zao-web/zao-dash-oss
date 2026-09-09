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
        Schema::create('meta_ad_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('account_id')->unique()->comment('Meta ad account ID (act_XXXXX)');
            $table->string('business_id')->nullable();
            $table->string('name');
            $table->text('access_token'); // Encrypted in model
            $table->timestamp('token_expires_at')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('timezone')->default('America/Los_Angeles');
            $table->enum('status', ['active', 'paused', 'archived'])->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('client_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meta_ad_accounts');
    }
};
