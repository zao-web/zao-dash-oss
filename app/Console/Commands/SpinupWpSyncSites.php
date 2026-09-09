<?php

namespace App\Console\Commands;

use App\Models\SpinupWpServer;
use App\Models\SpinupWpSite;
use App\Services\SpinupWp\SpinupWpService;
use Illuminate\Console\Command;

class SpinupWpSyncSites extends Command
{
    protected $signature = 'spinupwp:sync-sites 
                            {--server= : Only sync sites from a specific server ID}
                            {--link-projects : Attempt to link sites to website projects by domain matching}';

    protected $description = 'Sync sites from SpinupWP API to local database';

    public function handle(SpinupWpService $service): int
    {
        if (! $service->isConfigured()) {
            $this->error('SpinupWP API is not configured. Set SPINUPWP_API_TOKEN in your environment.');

            return self::FAILURE;
        }

        $this->info('Syncing servers...');
        $serverCount = $service->syncServers();
        $this->info("Synced {$serverCount} servers.");

        $serverId = $this->option('server');
        $this->info('Fetching sites from SpinupWP API...');

        $sites = $service->listSites($serverId ? (int) $serverId : null);
        $this->info('Found '.count($sites).' sites.');

        $created = 0;
        $updated = 0;

        foreach ($sites as $siteData) {
            $server = SpinupWpServer::where('spinup_id', $siteData['server_id'])->first();

            if (! $server) {
                $this->warn("Server not found for site {$siteData['domain']}, skipping.");

                continue;
            }

            $site = SpinupWpSite::where('spinup_id', $siteData['id'])->first();

            if ($site) {
                $site->updateFromApi($siteData);
                $updated++;
                $this->line("Updated: {$siteData['domain']}");
            } else {
                $site = SpinupWpSite::create([
                    'spinup_id' => $siteData['id'],
                    'spinup_server_id' => $server->id,
                    'domain' => $siteData['domain'],
                    'additional_domains' => $siteData['additional_domains'] ?? [],
                    'site_user' => $siteData['site_user'] ?? null,
                    'php_version' => $siteData['php_version'] ?? '8.3',
                    'public_folder' => $siteData['public_folder'] ?? 'public',
                    'is_wordpress' => $siteData['is_wordpress'] ?? true,
                    'page_cache_enabled' => $siteData['page_cache']['enabled'] ?? false,
                    'https_enabled' => $siteData['https']['enabled'] ?? true,
                    'nginx_config' => $siteData['nginx'] ?? null,
                    'database_config' => $siteData['database'] ?? null,
                    'backup_config' => $siteData['backups'] ?? null,
                    'git_config' => $siteData['git'] ?? null,
                    'basic_auth_enabled' => $siteData['basic_auth']['enabled'] ?? false,
                    'basic_auth_username' => $siteData['basic_auth']['username'] ?? null,
                    'status' => $siteData['status'] ?? 'deployed',
                    'last_synced_at' => now(),
                ]);
                $created++;
                $this->line("Created: {$siteData['domain']}");
            }

            if ($this->option('link-projects') && ! $site->website_project_id) {
                $this->linkToProject($site);
            }
        }

        $this->newLine();
        $this->info("Sync complete. Created: {$created}, Updated: {$updated}");

        return self::SUCCESS;
    }

    protected function linkToProject(SpinupWpSite $site): void
    {
        $domain = $site->domain;

        $project = \App\Models\WebsiteProject::where('staging_url', 'like', "%{$domain}%")
            ->orWhere('production_url', 'like', "%{$domain}%")
            ->orWhere('domain', $domain)
            ->first();

        if ($project) {
            $site->update(['website_project_id' => $project->id]);
            $this->info("  -> Linked to project: {$project->name} (ID: {$project->id})");
        }
    }
}
