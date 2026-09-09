<?php

namespace App\Console\Commands;

use App\Models\MetaAdAccount;
use Illuminate\Console\Command;

class UpdateMetaToken extends Command
{
    protected $signature = 'meta:update-token {token} {account_id=1}';

    protected $description = 'Update Meta Ad Account access token';

    public function handle()
    {
        $accountId = $this->argument('account_id');
        $token = $this->argument('token');

        $account = MetaAdAccount::findOrFail($accountId);
        $account->access_token = $token;
        $account->save();

        $this->info("Updated account {$account->name} (ID: {$account->id})");
        $this->info('Token length: '.strlen($account->access_token).' characters');
        $this->info('Token preview: '.substr($account->access_token, 0, 20).'...');

        return 0;
    }
}
