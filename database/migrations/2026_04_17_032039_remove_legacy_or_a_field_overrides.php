<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // OR-A was rewritten from a few generic keys (charitable, mortgage_interest,
        // property_taxes, total) to the proper line_1..line_21 numbering across
        // pages 1-2. Drop stale overrides pointing at the old keys so the new
        // config defaults apply on the next form generation.
        DB::table('tax_form_field_overrides')
            ->where('form_code', 'or-a')
            ->whereIn('field_key', ['charitable', 'mortgage_interest', 'property_taxes', 'total'])
            ->delete();
    }

    public function down(): void
    {
        // No rollback — these key names no longer exist in the OR-A config or mapper.
    }
};
