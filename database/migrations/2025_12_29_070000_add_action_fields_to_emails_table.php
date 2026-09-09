<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            if (! Schema::hasColumn('emails', 'project_id')) {
                $table->foreignId('project_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('emails', 'action_required')) {
                $table->boolean('action_required')->default(false)->after('is_processed');
            }
            if (! Schema::hasColumn('emails', 'action_summary')) {
                $table->text('action_summary')->nullable()->after('action_required');
            }
            if (! Schema::hasColumn('emails', 'urgency')) {
                $table->string('urgency')->nullable()->after('action_summary');
            }
            if (! Schema::hasColumn('emails', 'ai_analyzed_at')) {
                $table->timestamp('ai_analyzed_at')->nullable()->after('urgency');
            }
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $columns = ['project_id', 'action_required', 'action_summary', 'urgency', 'ai_analyzed_at'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('emails', $col)) {
                    if ($col === 'project_id') {
                        $table->dropForeign(['project_id']);
                    }
                    $table->dropColumn($col);
                }
            }
        });
    }
};
