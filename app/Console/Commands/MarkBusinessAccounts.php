<?php

namespace App\Console\Commands;

use App\Models\PersonalAccount;
use Illuminate\Console\Command;

class MarkBusinessAccounts extends Command
{
    protected $signature = 'accounts:mark-business {ids* : Account IDs to mark as business}';

    protected $description = 'Mark personal accounts as business accounts (is_business = true)';

    public function handle(): int
    {
        $ids = $this->argument('ids');

        $accounts = PersonalAccount::whereIn('id', $ids)->get();

        if ($accounts->isEmpty()) {
            $this->error('No accounts found with those IDs.');

            return self::FAILURE;
        }

        foreach ($accounts as $account) {
            $account->update(['is_business' => true]);
            $this->info("Marked account #{$account->id} ({$account->name}) as business.");
        }

        $this->newLine();
        $this->info('Done. Re-run `php artisan tax:audit 2025` to see the updated numbers.');

        return self::SUCCESS;
    }
}
