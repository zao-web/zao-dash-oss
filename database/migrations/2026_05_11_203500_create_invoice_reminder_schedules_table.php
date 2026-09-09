<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_reminder_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('scope'); // 'global' or 'client'
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true); // master toggle for this scope
            $table->json('schedule'); // [{offset_days: int, enabled: bool}, ...]
            $table->timestamps();

            $table->unique(['scope', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_reminder_schedules');
    }
};
