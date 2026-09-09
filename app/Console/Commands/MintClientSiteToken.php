<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;

/**
 * Mint a per-client Sanctum token for a deployed client site (the Zao Assistant
 * WordPress widget). The token is tokenable by the Client, so it can only ever
 * reach that client's data via /mcp/zao-client. Paste the printed token into the
 * site's Zao Assistant settings ("Zao token"); the plaintext is shown once.
 *
 *   php artisan zao:client-token acme-co
 *   php artisan zao:client-token acme-co --revoke   # revoke existing site tokens
 */
class MintClientSiteToken extends Command
{
    protected $signature = 'zao:client-token {client : Client id or slug} {--name=site : Token name} {--revoke : Revoke this client\'s existing site tokens first}';

    protected $description = 'Mint a per-client scoped MCP token for a deployed client site (Zao Assistant).';

    public function handle(): int
    {
        $key = $this->argument('client');
        $client = is_numeric($key)
            ? Client::find((int) $key)
            : Client::where('slug', $key)->first();

        if (! $client) {
            $this->error("No client matching '{$key}'.");

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $count = $client->tokens()->where('name', 'like', 'site:%')->delete();
            $this->warn("Revoked {$count} existing site token(s) for {$client->name}.");
        }

        // Ability 'client-site' documents intent; isolation is enforced by the
        // tokenable being a Client + EnsureClientToken, not by the ability string.
        $token = $client->createToken('site:'.$client->slug, ['client-site']);

        $this->info("Token for {$client->name} (#{$client->id}):");
        $this->line('');
        $this->line($token->plainTextToken);
        $this->line('');
        $this->comment('Shown once. Paste it into the site\'s Zao Assistant settings ("Zao token").');
        $this->comment('MCP URL: '.rtrim(config('app.url'), '/').'/mcp/zao-client');

        return self::SUCCESS;
    }
}
