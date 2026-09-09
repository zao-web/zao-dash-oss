<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // Recording timestamp (explicit, defaults to created_at)
            $table->timestamp('recorded_at')->nullable()->after('recording_metadata');

            // Transcription fields
            $table->longText('transcript')->nullable()->after('recorded_at');
            $table->json('transcript_segments')->nullable()->after('transcript');
            $table->string('transcript_language', 10)->nullable()->after('transcript_segments');
            $table->string('transcript_status', 20)->nullable()->after('transcript_language');

            // AI analysis fields
            $table->text('ai_summary')->nullable()->after('transcript_status');
            $table->json('ai_action_items')->nullable()->after('ai_summary');
            $table->timestamp('ai_processed_at')->nullable()->after('ai_action_items');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn([
                'recorded_at',
                'transcript',
                'transcript_segments',
                'transcript_language',
                'transcript_status',
                'ai_summary',
                'ai_action_items',
                'ai_processed_at',
            ]);
        });
    }
};
