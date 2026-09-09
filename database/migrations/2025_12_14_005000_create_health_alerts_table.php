<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->string('alert_type'); // health_critical, health_warning, sentiment_negative, churn_risk
            $table->string('severity'); // low, medium, high, critical
            $table->decimal('health_score', 4, 1)->nullable();
            $table->decimal('previous_score', 4, 1)->nullable();
            $table->text('description');
            $table->json('factors')->nullable(); // What contributed to this alert
            $table->string('status')->default('open'); // open, acknowledged, escalated, resolved
            $table->integer('escalation_level')->default(0); // 0=initial, 1=manager, 2=director, 3=executive
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('next_escalation_at')->nullable();
            $table->json('escalation_history')->nullable(); // Track escalation events
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['status', 'next_escalation_at']);
            $table->index('severity');
        });

        // Track escalation targets for each level
        Schema::create('escalation_targets', function (Blueprint $table) {
            $table->id();
            $table->integer('level'); // 0=account_manager, 1=manager, 2=director, 3=executive
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0); // Priority within level
            $table->timestamps();

            $table->unique(['level', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalation_targets');
        Schema::dropIfExists('health_alerts');
    }
};
