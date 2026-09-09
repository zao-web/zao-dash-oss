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
        Schema::create('tax_agency_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('agency_code');
            $table->string('agency_name');
            $table->string('portal_name');
            $table->string('status')->default('needs_auth');
            $table->string('auth_mode')->default('browser_session_bridge');
            $table->longText('auth_payload')->nullable();
            $table->json('capabilities')->nullable();
            $table->boolean('sync_enabled')->default(true);
            $table->string('sync_status')->default('pending');
            $table->integer('sync_progress')->default(0);
            $table->text('sync_error')->nullable();
            $table->decimal('latest_balance_amount', 12, 2)->nullable();
            $table->string('latest_balance_status')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('latest_notice_at')->nullable();
            $table->timestamp('latest_transcript_at')->nullable();
            $table->timestamp('sync_started_at')->nullable();
            $table->timestamp('sync_completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'agency_code']);
            $table->index(['user_id', 'agency_code', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_agency_connections');
    }
};
