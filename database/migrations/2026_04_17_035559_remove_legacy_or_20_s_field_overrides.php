<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // OR-20-S was rewritten from a few generic keys (gross_receipts,
        // ordinary_income, minimum_tax, tax_due) into the proper line_N + named
        // identity scheme spanning all 8 pages. Drop stale overrides pointing at
        // the old keys so the new config defaults apply on the next form gen.
        // entity_name and entity_ein are also dropped because their previous
        // calibrated positions were for an outdated layout.
        DB::table('tax_form_field_overrides')
            ->where('form_code', 'or-20-s')
            ->whereIn('field_key', [
                'gross_receipts',
                'ordinary_income',
                'minimum_tax',
                'tax_due',
                'entity_name',
                'entity_ein',
            ])
            ->delete();
    }

    public function down(): void
    {
        // No rollback — these keys (or their old positions) no longer make sense.
    }
};
