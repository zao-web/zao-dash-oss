<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GenerateApiToken extends Command
{
    protected $signature = 'token:generate {email? : User email} {--name=video-recorder : Token name}';

    protected $description = 'Generate a personal access token for a user';

    public function handle(): int
    {
        $email = $this->argument('email');

        if (! $email) {
            $email = $this->ask('Enter user email');
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("User with email '{$email}' not found.");

            return 1;
        }

        $tokenName = $this->option('name');
        $token = $user->createToken($tokenName);

        $this->newLine();
        $this->info('Token generated successfully!');
        $this->newLine();
        $this->line('Copy this token (it will only be shown once):');
        $this->newLine();
        $this->comment($token->plainTextToken);
        $this->newLine();

        return 0;
    }
}
