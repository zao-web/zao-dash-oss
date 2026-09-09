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
        Schema::create('tax_agency_mfa_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_agency_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('slack_workspace_id')->nullable()->constrained('slack_workspaces')->nullOnDelete();
            $table->string('agency_code', 64);
            $table->string('challenge_type', 64)->default('sms_code');
            $table->string('delivery_method', 64)->default('slack_dm');
            $table->string('status', 32)->default('pending');
            $table->string('slack_channel_id')->nullable();
            $table->string('slack_user_id')->nullable();
            $table->string('response_message_ts')->nullable();
            $table->text('prompt_message')->nullable();
            $table->text('response_code')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'agency_code', 'status']);
            $table->index(['slack_workspace_id', 'slack_user_id', 'status']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_agency_mfa_challenges');
    }
};
