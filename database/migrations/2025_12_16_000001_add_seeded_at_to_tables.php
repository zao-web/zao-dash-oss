<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that contain seeded/demo data that should be cleanable for go-live.
     */
    protected array $tables = [
        'clients',
        'client_contacts',
        'projects',
        'milestones',
        'tasks',
        'agents',
        'agent_runs',
        'vault_secrets',
        'leads',
        'contractors',
        'contractor_invoices',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'seeded_at')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->timestamp('seeded_at')->nullable()->after('updated_at');
                    $table->index('seeded_at');
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'seeded_at')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropIndex(['seeded_at']);
                    $table->dropColumn('seeded_at');
                });
            }
        }
    }
};
