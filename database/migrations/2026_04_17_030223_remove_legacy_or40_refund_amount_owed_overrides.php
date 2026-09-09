<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tax_form_field_overrides')
            ->where('form_code', 'or-40')
            ->whereIn('field_key', ['refund', 'amount_owed'])
            ->delete();
    }

    public function down(): void
    {
        // No rollback — these were vestigial alias overrides that pointed to the
        // signature-only page 7. Restoring them would reintroduce the visual bug.
    }
};
