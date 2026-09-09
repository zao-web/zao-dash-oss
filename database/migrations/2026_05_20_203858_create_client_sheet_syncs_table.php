<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_sheet_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('spreadsheet_id');
            $table->string('sheet_title')->nullable();              // tab name; null = first tab
            $table->unsignedInteger('sheet_gid')->nullable();       // numeric tab id, more stable than title
            $table->unsignedInteger('header_row')->default(1);      // which row holds column headers
            $table->json('column_map');                             // logical name → header label or A1 column letter
            $table->string('zao_id_column_letter')->nullable();     // resolved on first sync
            $table->string('status_column_letter')->nullable();     // resolved on first sync
            $table->boolean('active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'spreadsheet_id', 'sheet_gid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_sheet_syncs');
    }
};
