#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build docs/environment.md from .env.example and env() calls.
 * Prints names and purposes only. Never writes values.
 *
 * Usage:
 *   php scripts/catalog-env.php
 *   php scripts/catalog-env.php --stdout
 *   php scripts/catalog-env.php --check
 */
$options = getopt('', ['stdout', 'check', 'root:', 'out:', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php scripts/catalog-env.php [--stdout] [--check] [--root=PATH] [--out=PATH]\n");
    exit(0);
}

$root = rtrim((string) ($options['root'] ?? dirname(__DIR__)), '/');
$outPath = $options['out'] ?? $root.'/docs/environment.md';
$stdout = array_key_exists('stdout', $options);
$check = array_key_exists('check', $options);

$entries = collectEntries($root);
ksort($entries);

$markdown = renderCatalog($entries);

if ($check) {
    $existing = is_file($outPath) ? (string) file_get_contents($outPath) : '';
    if ($existing !== $markdown) {
        fwrite(STDERR, "docs/environment.md is stale. Run: php scripts/catalog-env.php\n");
        exit(1);
    }

    fwrite(STDOUT, 'Catalog is current. '.count($entries)." variables.\n");
    exit(0);
}

if ($stdout) {
    fwrite(STDOUT, $markdown);
    exit(0);
}

file_put_contents($outPath, $markdown);
fwrite(STDOUT, 'Wrote '.$outPath.' ('.count($entries)." variables).\n");
exit(0);

/**
 * @return array<string, array{name: string, purpose: string, sources: list<string>, status: string}>
 */
function collectEntries(string $root): array
{
    $entries = [];

    $example = $root.'/.env.example';
    if (is_file($example)) {
        foreach (file($example, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*#?\s*([A-Z][A-Z0-9_]*)=/', $line, $match) !== 1) {
                continue;
            }

            remember($entries, $match[1], '.env.example');
        }
    }

    $scanRoots = ['config', 'app', 'routes', 'bootstrap'];
    $pattern = '/env\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]([^)]*)\)/';

    foreach ($scanRoots as $scanRoot) {
        $directory = $root.'/'.$scanRoot;
        if (! is_dir($directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $hint = trailingHint($match[2] ?? '');
                remember($entries, $match[1], $relative, $hint);
            }
        }
    }

    foreach ($entries as $name => $entry) {
        $entries[$name]['purpose'] = purposeFor($name, $entry['hints'], $entry['sources']);
        $entries[$name]['status'] = statusFor($name, $entry['sources']);
        unset($entries[$name]['hints']);
    }

    return $entries;
}

/**
 * @param  array<string, array{name: string, sources: list<string>, hints: list<string>}>  $entries
 * @param  list<string>  $hints
 */
function remember(array &$entries, string $name, string $source, string $hint = ''): void
{
    if (! isset($entries[$name])) {
        $entries[$name] = [
            'name' => $name,
            'sources' => [],
            'hints' => [],
        ];
    }

    if (! in_array($source, $entries[$name]['sources'], true)) {
        $entries[$name]['sources'][] = $source;
    }

    if ($hint !== '' && ! in_array($hint, $entries[$name]['hints'], true)) {
        $entries[$name]['hints'][] = $hint;
    }
}

function trailingHint(string $callTail): string
{
    if (preg_match('/\/\/\s*(.+)$/', $callTail, $match) !== 1) {
        return '';
    }

    $hint = trim($match[1]);
    if ($hint === '' || looksLikeSecret($hint)) {
        return '';
    }

    return $hint;
}

function looksLikeSecret(string $value): bool
{
    if (preg_match('/(sk-|xox[baprs]-|ghp_|AKIA|BEGIN [A-Z ]+PRIVATE KEY)/', $value) === 1) {
        return true;
    }

    return (bool) preg_match('/^[A-Za-z0-9+\/=_-]{24,}$/', $value);
}

/**
 * @param  list<string>  $hints
 * @param  list<string>  $sources
 */
function purposeFor(string $name, array $hints, array $sources): string
{
    $exact = exactPurposes();
    if (isset($exact[$name])) {
        return $exact[$name];
    }

    foreach (prefixPurposes() as $prefix => $purpose) {
        if (str_starts_with($name, $prefix)) {
            return $purpose;
        }
    }

    if ($hints !== []) {
        return rtrim($hints[0], '.').'.';
    }

    $configSources = array_values(array_filter(
        $sources,
        static fn (string $source): bool => str_starts_with($source, 'config/'),
    ));

    if ($configSources !== []) {
        return 'Read by '.implode(', ', array_slice($configSources, 0, 3)).'.';
    }

    $codeSources = array_values(array_filter(
        $sources,
        static fn (string $source): bool => $source !== '.env.example',
    ));

    if ($codeSources !== []) {
        return 'Referenced in '.implode(', ', array_slice($codeSources, 0, 2)).'.';
    }

    return 'Listed in .env.example.';
}

/**
 * @param  list<string>  $sources
 */
function statusFor(string $name, array $sources): string
{
    foreach (['PLAID_', 'TELLER_', 'TAX_AGENCY_', 'WISE_', 'MAXMIND_', 'IP_API_', 'IPINFO_', 'IPDATA_', 'IP2LOCATIONIO_', 'KLOUDEND_'] as $prefix) {
        if (str_starts_with($name, $prefix)) {
            return 'stub';
        }
    }

    if (in_array($name, ['LOCATION_TESTING'], true)) {
        return 'stub';
    }

    $framework = [
        'APP_', 'DB_', 'LOG_', 'SESSION_', 'BROADCAST_', 'CACHE_', 'QUEUE_',
        'REDIS_', 'MEMCACHED_', 'MAIL_', 'AWS_', 'VITE_', 'BCRYPT_', 'FILESYSTEM_',
        'REVERB_', 'AUTH_', 'SANCTUM_', 'HORIZON_', 'NIGHTWATCH_',
    ];

    foreach ($framework as $prefix) {
        if (str_starts_with($name, $prefix)) {
            return 'framework';
        }
    }

    $inExample = in_array('.env.example', $sources, true);
    $inConfig = array_filter($sources, static fn (string $source): bool => str_starts_with($source, 'config/'));

    if ($inExample || $inConfig !== []) {
        return 'configure';
    }

    return 'code-only';
}

/**
 * @return array<string, string>
 */
function exactPurposes(): array
{
    return [
        'APP_NAME' => 'Product name shown in the UI, mail, and session key prefix.',
        'APP_ENV' => 'Runtime environment. Use local for a first run.',
        'APP_KEY' => 'Laravel encryption key. Generate with php artisan key:generate. Never commit a real key.',
        'APP_DEBUG' => 'Shows detailed errors when true. Keep false on any shared host.',
        'APP_URL' => 'Public URL of this app. MCP clients and OAuth redirects use it.',
        'APP_LOCALE' => 'Default locale.',
        'APP_FALLBACK_LOCALE' => 'Locale used when a translation is missing.',
        'APP_FAKER_LOCALE' => 'Faker locale for seeders and factories.',
        'APP_MAINTENANCE_DRIVER' => 'Driver for php artisan down.',
        'AGENT_INTERNAL_TOKEN' => 'Shared secret for agent and website-builder callbacks. Leave empty until you run those jobs.',
        'ZAO_DASH_MCP_TOKEN' => 'Client-side Sanctum token for MCP. Create with php artisan mcp:token. Do not put a real token in .env.example.',
        'COMPANY_NAME' => 'Legal or trading name printed on invoices.',
        'COMPANY_EMAIL' => 'From address and invoicer fallback for billing mail.',
        'COMPANY_PHONE' => 'Phone printed on invoices.',
        'COMPANY_ADDRESS' => 'Address printed on invoices.',
        'COMPANY_LOGO_URL' => 'Logo URL printed on invoices.',
        'CLIENT_EMAIL_CC' => 'Extra CC on client emails.',
        'BANK_NAME' => 'Bank name printed as ACH instructions. Empty hides the block.',
        'BANK_ROUTING_NUMBER' => 'ACH routing number printed on invoices. Empty hides the block.',
        'BANK_ACCOUNT_NUMBER' => 'ACH account number printed on invoices. Empty hides the block.',
        'BANK_ACCOUNT_NAME' => 'Account holder name printed on invoices.',
        'FILESYSTEM_DISK' => 'Default filesystem disk. Use s3 on Laravel Cloud.',
        'QUEUE_CONNECTION' => 'Queue driver. database is enough locally.',
        'BROADCAST_CONNECTION' => 'Broadcast driver. .env.example uses reverb.',
        'CACHE_STORE' => 'Cache store. database is enough locally.',
        'TRANSCRIPTION_PROVIDER' => 'Which speech-to-text provider agents use.',
        'TRANSCRIPTION_MODEL' => 'Model id for the selected transcription provider.',
        'SELF_DEVELOPMENT_ENABLED' => 'Allows the in-app feature-request path.',
        'SELF_PROJECT_SLUG' => 'Project slug used by self-development tasks.',
        'SELF_PROJECT_ID' => 'Fallback project id when the slug is missing.',
        'SELF_AUTO_TRIGGER_AGENT' => 'When true, a feature request starts the dev agent.',
        'SELF_FEATURE_PRIORITY' => 'Default priority for self-development tasks.',
        'SELF_GITHUB_REPO' => 'owner/repo string given to the dev agent. Keep the example placeholder.',
        'OLLIE_PRO_PLUGIN_URL' => 'URL of the Ollie Pro plugin zip used by the site builder. Optional.',
        'FFMPEG_PATH' => 'Path to the ffmpeg binary for video jobs.',
        'FFPROBE_PATH' => 'Path to the ffprobe binary for video jobs.',
        'CHROME_PATH' => 'Chrome binary for the tax-agency bridge. That bridge is stubbed in this snapshot.',
        'GOTENBERG_URL' => 'Optional Gotenberg URL for PDF rendering.',
        'BROWSERLESS_API_KEY' => 'Optional Browserless key for headless browsing.',
        'LINEAR_API_KEY' => 'Optional Linear API key referenced by application code.',
        'LARAVEL_CLOUD' => 'Set by Laravel Cloud. Do not invent a local value.',
        'LOCATION_TESTING' => 'Location package test flag. Household geo lookups have no public HTTP route in this snapshot.',
    ];
}

/**
 * @return array<string, string>
 */
function prefixPurposes(): array
{
    return [
        'ANTHROPIC_' => 'Anthropic API credentials for in-app agents. Leave empty to skip model calls.',
        'OPENAI_' => 'OpenAI API credentials. Leave empty to skip those model calls.',
        'GEMINI_' => 'Google Gemini API credentials.',
        'GROK_' => 'xAI Grok API credentials and model id.',
        'GROQ_' => 'Groq API key, used when transcription or agents select Groq.',
        'OPENROUTER_' => 'OpenRouter API key for routed model calls.',
        'CLAUDE_CODE_' => 'Claude CLI OAuth token for local Claude Code runs. Distinct from ANTHROPIC_API_KEY.',
        'REPLICATE_' => 'Replicate token for image generation.',
        'SLACK_' => 'Slack app OAuth, signing secret, and bot token. Empty disables Slack.',
        'GOOGLE_' => 'Google OAuth and related API keys for Gmail, Calendar, and Drive.',
        'GITHUB_' => 'GitHub App or OAuth credentials. Empty disables GitHub sync.',
        'HARVEST_' => 'Harvest time-tracking OAuth. Empty disables Harvest sync.',
        'QUICKBOOKS_' => 'QuickBooks Online OAuth. Keep the environment on sandbox until you intend to write books.',
        'PAYPAL_' => 'PayPal invoicing. Keep PAYPAL_MODE=sandbox until go-live.',
        'NOTION_' => 'Notion OAuth. Empty disables Notion sync.',
        'SPINUPWP_' => 'SpinupWP hosting API and SSH material for the website builder.',
        'X_' => 'X (Twitter) OAuth.',
        'LINKEDIN_' => 'LinkedIn OAuth.',
        'SERPER_' => 'Serper web-search key for agent research.',
        'SERPAPI_' => 'SerpAPI key for search-result lookups.',
        'UNSPLASH_' => 'Unsplash API credentials for stock images.',
        'META_ADS_' => 'Meta ads API credentials. Sandbox keys are separate from live account keys.',
        'GRAVITY_FORMS_' => 'Gravity Forms REST credentials and webhook secret.',
        'WP_APPLICATION_' => 'WordPress application user and password for Gravity Forms or site calls.',
        'CLOUDFLARE_' => 'Cloudflare account and Workers AI settings.',
        'SAM_GOV_' => 'SAM.gov API key for RFP discovery.',
        'FACEBOOK_' => 'Facebook page token.',
        'YELP_' => 'Yelp API key.',
        'PLAID_' => 'Plaid keys. Listed so older notes parse. Personal-bank HTTP and MCP are stubbed in this snapshot.',
        'TELLER_' => 'Teller certificates and keys. Personal-bank HTTP and MCP are stubbed in this snapshot.',
        'WISE_' => 'Wise API credentials. Household transfer tools are stubbed in this snapshot.',
        'TAX_AGENCY_' => 'Tax-agency bridge settings. Those HTTP routes and MCP tools are stubbed in this snapshot.',
        'AGENT_' => 'Agent runtime limits, models, and circuit-breaker settings.',
        'VITE_' => 'Values exposed to the Vite frontend. Do not put secrets here.',
        'REVERB_' => 'Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets.',
        'MAIL_' => 'Outbound mail. Defaults are local placeholders.',
        'AWS_' => 'S3 credentials for the durable upload disk. Needed on Laravel Cloud.',
        'DB_' => 'Database connection. SQLite is the local default.',
        'REDIS_' => 'Redis connection for cache, queues, or Horizon.',
        'SESSION_' => 'Session cookie and driver settings.',
        'LOG_' => 'Log channel and level.',
        'HORIZON_' => 'Laravel Horizon settings.',
        'NIGHTWATCH_' => 'Laravel Nightwatch settings.',
        'SANCTUM_' => 'Laravel Sanctum settings.',
        'LARAVEL_CLOUD_' => 'Laravel Cloud API identifiers for environment control. Optional locally.',
    ];
}

/**
 * @param  array<string, array{name: string, purpose: string, sources: list<string>, status: string}>  $entries
 */
function renderCatalog(array $entries): string
{
    $count = count($entries);
    $lines = [];
    $lines[] = '# Environment variables';
    $lines[] = '';
    $lines[] = 'Reference for every environment variable this tree reads. Generated from `.env.example` and `env()` calls in `config/`, `app/`, `routes/`, and `bootstrap/`.';
    $lines[] = '';
    $lines[] = 'Regenerate after a config change:';
    $lines[] = '';
    $lines[] = '```bash';
    $lines[] = 'php scripts/catalog-env.php';
    $lines[] = '```';
    $lines[] = '';
    $lines[] = 'This file lists names and purposes only. It does not include values. Do not paste real keys into docs or commits.';
    $lines[] = '';
    $lines[] = 'Status values:';
    $lines[] = '';
    $lines[] = '- `framework` is Laravel or frontend runtime. Set these on first run.';
    $lines[] = '- `configure` is an integration or product setting. An empty value disables that integration.';
    $lines[] = '- `stub` is present so older notes and migrations still parse. Those HTTP routes and MCP tools are not registered in this snapshot.';
    $lines[] = '- `code-only` is read by application code and is absent from `.env.example`.';
    $lines[] = '';
    $lines[] = "Catalog size: {$count}.";
    $lines[] = '';
    $lines[] = '| Variable | Status | Purpose | Sources |';
    $lines[] = '| --- | --- | --- | --- |';

    foreach ($entries as $entry) {
        $sources = array_map(
            static fn (string $source): string => '`'.$source.'`',
            array_slice($entry['sources'], 0, 4),
        );
        if (count($entry['sources']) > 4) {
            $sources[] = '+'.(count($entry['sources']) - 4);
        }

        $lines[] = '| `'.$entry['name'].'` | '.$entry['status'].' | '.escapeCell($entry['purpose']).' | '.implode(', ', $sources).' |';
    }

    $lines[] = '';

    return implode("\n", $lines);
}

function escapeCell(string $value): string
{
    return str_replace('|', '\\|', $value);
}
