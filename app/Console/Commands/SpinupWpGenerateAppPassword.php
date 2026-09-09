<?php

namespace App\Console\Commands;

use App\Models\SpinupWpSite;
use App\Services\SpinupWp\SpinupWpSshService;
use Illuminate\Console\Command;

class SpinupWpGenerateAppPassword extends Command
{
    protected $signature = 'spinupwp:generate-app-password
        {--site= : SpinupWP site ID or domain}
        {--project= : Website project ID to find associated site}
        {--app-name=Zao Dash API : Name for the application password}
        {--discover-user : Discover admin username via WP-CLI if not set}';

    protected $description = 'Generate a WordPress application password for a SpinupWP site via SSH/WP-CLI';

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

        if ($this->option('discover-user') || ! $site->wp_admin_user) {
            $this->info('Discovering WordPress admin user...');
            $userResult = $sshService->discoverAdminUser($site);

            if (! $userResult['success']) {
                $this->error("Failed to discover admin user: {$userResult['error']}");

                return self::FAILURE;
            }

            $site->refresh();
            $this->info("Admin user: {$userResult['username']}");
        }

        if (! $site->wp_admin_user) {
            $this->error('No WordPress admin user configured. Use --discover-user to find one.');

            return self::FAILURE;
        }

        $this->info("WordPress user: {$site->wp_admin_user}");
        $this->info('Generating application password...');

        $appName = $this->option('app-name');
        $result = $sshService->generateApplicationPassword($site, $appName);

        if (! $result['success']) {
            $this->error("Failed: {$result['error']}");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Application password generated successfully!');
        $this->table(
            ['Field', 'Value'],
            [
                ['Domain', $site->domain],
                ['Username', $site->wp_admin_user],
                ['Password', $result['password']],
                ['WordPress Site ID', $result['wordpress_site']->id],
            ]
        );

        $this->newLine();
        $this->info('The password has been stored in both spinup_wp_sites and wordpress_sites tables.');

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
