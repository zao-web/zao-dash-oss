<?php

namespace App\Console\Commands;

use App\Models\GoogleCredential;
use Illuminate\Console\Command;

class CheckGoogleCredentials extends Command
{
    protected $signature = 'google:check {--fix : Deactivate broken credentials}';

    protected $description = 'Check Google OAuth credentials for issues';

    public function handle(): int
    {
        $credentials = GoogleCredential::with('user')->get();

        if ($credentials->isEmpty()) {
            $this->info('No Google credentials found.');

            return 0;
        }

        $this->table(
            ['ID', 'User', 'Email', 'Active', 'Expires', 'Has Access Token', 'Has Refresh Token', 'Status'],
            $credentials->map(fn ($c) => [
                $c->id,
                $c->user?->name ?? 'No User',
                $c->email ?? 'N/A',
                $c->is_active ? 'Yes' : 'No',
                $c->expires_at?->format('Y-m-d H:i') ?? 'N/A',
                $c->access_token ? 'Yes' : 'NO',
                $c->refresh_token ? 'Yes' : 'NO',
                $this->getStatus($c),
            ])
        );

        $broken = $credentials->filter(fn ($c) => ! $c->access_token && ! $c->refresh_token);

        if ($broken->isNotEmpty()) {
            $this->newLine();
            $this->error("Found {$broken->count()} credential(s) with missing tokens!");
            $this->warn('This usually means:');
            $this->line('  1. APP_KEY changed and encrypted tokens cannot be decrypted');
            $this->line('  2. OAuth flow was incomplete (no refresh token granted)');
            $this->line('  3. Tokens were manually cleared');
            $this->newLine();
            $this->info('To fix: User needs to disconnect and reconnect Google in Settings > Integrations');

            if ($this->option('fix')) {
                $this->newLine();
                foreach ($broken as $cred) {
                    $cred->update(['is_active' => false]);
                    $this->warn("Deactivated credential #{$cred->id} for user {$cred->user?->name}");
                }
                $this->info('Broken credentials have been deactivated to prevent sync errors.');
            } else {
                $this->newLine();
                $this->line('Run with --fix to deactivate broken credentials.');
            }
        }

        return 0;
    }

    private function getStatus(GoogleCredential $c): string
    {
        if (! $c->access_token && ! $c->refresh_token) {
            return '❌ BROKEN - No tokens';
        }
        if (! $c->refresh_token) {
            return '⚠️ No refresh token';
        }
        if ($c->expires_at?->isPast() ?? false) {
            return '🔄 Expired (will refresh)';
        }

        return '✅ OK';
    }
}
