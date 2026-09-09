<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SetupGitHubKey extends Command
{
    protected $signature = 'github:setup-key {--test : Test current key configuration}';

    protected $description = 'Setup or test GitHub App private key';

    public function handle()
    {
        if ($this->option('test')) {
            return $this->testKey();
        }

        $this->info('GitHub Key Setup');
        $this->line('');

        // Check what's currently configured
        $this->line('Current configuration:');
        $this->line('  GITHUB_APP_PRIVATE_KEY_PATH: '.(env('GITHUB_APP_PRIVATE_KEY_PATH') ?: '(not set)'));
        $this->line('  GITHUB_APP_PRIVATE_KEY_BASE64 length: '.strlen(env('GITHUB_APP_PRIVATE_KEY_BASE64') ?? ''));
        $this->line('  GITHUB_APP_PRIVATE_KEY length: '.strlen(env('GITHUB_APP_PRIVATE_KEY') ?? ''));
        $this->line('');

        $this->info('To set up the key, paste your base64-encoded private key:');
        $this->line('  php artisan tinker');
        $this->line('  >>> file_put_contents(storage_path("github-key.pem"), base64_decode("YOUR_BASE64_KEY"));');
        $this->line('');
        $this->line('Then set: GITHUB_APP_PRIVATE_KEY_PATH='.storage_path('github-key.pem'));

        return 0;
    }

    private function testKey(): int
    {
        $this->info('Testing GitHub Key Configuration...');
        $this->line('');

        // Check config values (from env via config:cache safe approach)
        $path = config('services.github.private_key_path');
        $base64 = config('services.github.private_key_base64');
        $direct = config('services.github.private_key');

        $this->line('Config Values:');
        $this->line('  GITHUB_APP_ID: '.(config('services.github.app_id') ?: '(not set)'));
        $this->line('  GITHUB_APP_SLUG: '.(config('services.github.app_slug') ?: '(not set)'));
        $this->line('  private_key_path: '.($path ?: '(not set)'));
        $this->line('  private_key_base64: '.($base64 ? strlen($base64).' chars' : '(not set)'));
        $this->line('  private_key: '.($direct ? strlen($direct).' chars' : '(not set)'));
        $this->line('');

        // Resolve key using same logic as GitHubAppService
        $key = $this->resolveKey($path, $base64, $direct);

        $this->line('Resolved Key:');
        $this->line('  Length: '.strlen($key ?? ''));
        $this->line('  First 50 chars: '.substr($key ?? '', 0, 50));
        $this->line('  Has BEGIN marker: '.(str_contains($key ?? '', '-----BEGIN') ? 'Yes' : 'No'));
        $this->line('  Has END marker: '.(str_contains($key ?? '', '-----END') ? 'Yes' : 'No'));
        $this->line('');

        if (empty($key)) {
            $this->error('Key is empty!');

            return 1;
        }

        if (! str_contains($key, '-----BEGIN')) {
            $this->error('Key is missing PEM header!');

            // Check if it looks like base64
            if (preg_match('/^[A-Za-z0-9+\/=]+$/', trim($key))) {
                $this->warn('Key looks like base64 - try decoding it');
                $decoded = base64_decode($key);
                $this->line('  Decoded length: '.strlen($decoded));
                $this->line('  Decoded first 50: '.substr($decoded, 0, 50));
            }

            return 1;
        }

        // Try to load with OpenSSL
        $this->line('OpenSSL Validation:');
        $keyResource = openssl_pkey_get_private($key);

        if ($keyResource === false) {
            $error = openssl_error_string();
            $this->error('  OpenSSL failed: '.$error);

            return 1;
        }

        $this->info('  Key is valid!');

        $details = openssl_pkey_get_details($keyResource);
        $this->line('  Key type: RSA');
        $this->line('  Key bits: '.($details['bits'] ?? 'unknown'));

        return 0;
    }

    private function resolveKey(?string $path, ?string $base64, ?string $direct): ?string
    {
        // Option 1: Explicit file path
        if ($path && file_exists($path)) {
            $this->line('  Source: file path');

            return file_get_contents($path);
        }

        // Option 2: Default storage location
        $storagePath = storage_path('github-key.pem');
        if (file_exists($storagePath)) {
            $this->line('  Source: storage/github-key.pem');

            return file_get_contents($storagePath);
        }

        // Option 3: Base64 encoded
        if ($base64) {
            $this->line('  Source: base64 config');

            return base64_decode($base64);
        }

        // Option 4: Direct value
        if ($direct) {
            $this->line('  Source: direct config');

            return $direct;
        }

        $this->line('  Source: none found');

        return null;
    }
}
