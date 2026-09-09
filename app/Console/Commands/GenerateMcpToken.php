<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GenerateMcpToken extends Command
{
    protected $signature = 'mcp:token {--user= : User ID or email} {--name=mcp-access : Token name}';

    protected $description = 'Generate a Sanctum token for MCP access';

    public function handle(): int
    {
        $identifier = $this->option('user');

        if ($identifier) {
            $user = is_numeric($identifier)
                ? User::find($identifier)
                : User::where('email', $identifier)->first();
        } else {
            $user = User::whereIn('role', ['owner', 'admin'])->first() ?? User::first();
        }

        if (! $user) {
            $this->error('No user found.');

            return 1;
        }

        $token = $user->createToken($this->option('name'))->plainTextToken;

        $this->info("Token generated for {$user->email}:");
        $this->newLine();
        $this->line($token);
        $this->newLine();
        $this->info('Add to your environment:');
        $this->line("export ZAO_DASH_MCP_TOKEN=\"{$token}\"");

        return 0;
    }
}
