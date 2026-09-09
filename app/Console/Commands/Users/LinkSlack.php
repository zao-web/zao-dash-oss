<?php

namespace App\Console\Commands\Users;

use App\Models\User;
use Illuminate\Console\Command;

class LinkSlack extends Command
{
    protected $signature = 'users:link-slack {user : User id or email} {slack_user_id : The Slack user ID, e.g. U00EXAMPLE01}';

    protected $description = 'Link a User to their Slack user ID so retainer reports treat them as internal team';

    public function handle(): int
    {
        $needle = $this->argument('user');
        $slackUserId = $this->argument('slack_user_id');

        $user = is_numeric($needle)
            ? User::find($needle)
            : User::where('email', $needle)->first();

        if (! $user) {
            $this->error("No user found for: {$needle}");

            return self::FAILURE;
        }

        $user->update(['slack_user_id' => $slackUserId]);

        $this->info("Linked {$user->email} → {$slackUserId}");

        return self::SUCCESS;
    }
}
