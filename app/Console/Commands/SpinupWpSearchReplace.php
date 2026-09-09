<?php

namespace App\Console\Commands;

use App\Models\SpinupWpSite;
use App\Services\SpinupWp\SpinupWpSshService;
use Illuminate\Console\Command;

class SpinupWpSearchReplace extends Command
{
    protected $signature = 'spinupwp:search-replace
        {search : The string to search for}
        {replace : The string to replace with}
        {--site= : SpinupWP site ID or domain}
        {--project= : Website project ID to find associated site}
        {--dry-run : Show what would be replaced without making changes}
        {--all-tables : Search all tables, not just WordPress tables}';

    protected $description = 'Run WP-CLI search-replace on a SpinupWP site via SSH';

    public function handle(SpinupWpSshService $sshService): int
    {
        $site = $this->resolveSite();

        if (! $site) {
            $this->error('Could not find SpinupWP site. Use --site=<id|domain> or --project=<id>');

            return self::FAILURE;
        }

        $this->info("Site: {$site->domain}");
        $this->info("Server: {$site->server?->name} ({$site->server?->ip_address})");

        if (! $site->server?->ip_address) {
            $this->error('Site has no server with IP address configured');

            return self::FAILURE;
        }

        $search = $this->argument('search');
        $replace = $this->argument('replace');

        $this->newLine();
        $this->info("Search:  {$search}");
        $this->info("Replace: {$replace}");
        $this->newLine();

        $args = [
            $search,
            $replace,
        ];

        if ($this->option('dry-run')) {
            $args[] = '--dry-run';
            $this->warn('DRY RUN - No changes will be made');
        }

        if ($this->option('all-tables')) {
            $args[] = '--all-tables';
        }

        $this->info('Running wp search-replace...');

        $result = $sshService->runWpCli($site, 'search-replace', $args);

        if (! $result['success']) {
            $this->error("Failed: {$result['error']}");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Output:');
        $this->line($result['output']);

        if (! $this->option('dry-run')) {
            $this->newLine();
            $this->info('Search-replace completed successfully!');
            $this->warn('Remember to flush caches if applicable.');
        }

        return self::SUCCESS;
    }

    protected function resolveSite(): ?SpinupWpSite
    {
        if ($siteOption = $this->option('site')) {
            if (is_numeric($siteOption)) {
                return SpinupWpSite::with('server')->find($siteOption);
            }

            return SpinupWpSite::with('server')->where('domain', $siteOption)->first();
        }

        if ($projectId = $this->option('project')) {
            return SpinupWpSite::with('server')->where('website_project_id', $projectId)->first();
        }

        $sites = SpinupWpSite::with('server')->get();

        if ($sites->isEmpty()) {
            return null;
        }

        if ($sites->count() === 1) {
            return $sites->first();
        }

        $choices = $sites->mapWithKeys(fn ($site) => [$site->id => "{$site->domain} (ID: {$site->id})"]);
        $selected = $this->choice('Select a site:', $choices->toArray());

        preg_match('/ID: (\d+)/', $selected, $matches);

        return SpinupWpSite::with('server')->find($matches[1]);
    }
}
