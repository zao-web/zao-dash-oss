<?php

namespace App\Services\SpinupWp;

use App\Models\SpinupWpServer;
use App\Models\SpinupWpSite;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SpinupWpService
{
    protected ?string $apiToken;

    protected string $apiUrl;

    public function __construct()
    {
        $this->apiToken = config('services.spinupwp.api_token');
        $this->apiUrl = config('services.spinupwp.api_url', 'https://api.spinupwp.app/v1');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiToken);
    }

    protected function client(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('SpinupWP API token not configured');
        }

        return Http::withToken($this->apiToken)
            ->withHeaders(['Accept' => 'application/json'])
            ->baseUrl($this->apiUrl)
            ->timeout(60);
    }

    protected function handleResponse(Response $response, string $operation): array
    {
        if (! $response->successful()) {
            $error = $response->json('message') ?? $response->body();
            throw new \Exception("SpinupWP {$operation} failed: {$error}");
        }

        return $response->json();
    }

    public function listServers(): array
    {
        $servers = [];
        $page = 1;

        do {
            $response = $this->client()->get('/servers', ['page' => $page, 'limit' => 100]);
            $data = $this->handleResponse($response, 'list servers');

            foreach ($data['data'] ?? [] as $server) {
                $servers[] = $server;
            }

            $hasMore = isset($data['pagination']['next']);
            $page++;
        } while ($hasMore);

        return $servers;
    }

    public function getServer(int $serverId): array
    {
        $response = $this->client()->get("/servers/{$serverId}");

        return $this->handleResponse($response, 'get server')['data'] ?? [];
    }

    public function syncServers(): int
    {
        $servers = $this->listServers();
        $count = 0;

        foreach ($servers as $serverData) {
            $server = SpinupWpServer::updateOrCreate(
                ['spinup_id' => $serverData['id']],
                [
                    'name' => $serverData['name'],
                    'provider_name' => $serverData['provider_name'] ?? null,
                    'ubuntu_version' => $serverData['ubuntu_version'] ?? null,
                    'ip_address' => $serverData['ip_address'] ?? null,
                    'ssh_port' => $serverData['ssh_port'] ?? 22,
                    'timezone' => $serverData['timezone'] ?? 'UTC',
                    'region' => $serverData['region'] ?? null,
                    'size' => $serverData['size'] ?? null,
                    'disk_space' => $serverData['disk_space'] ?? null,
                    'database_config' => $serverData['database'] ?? null,
                    'ssh_publickey' => $serverData['ssh_publickey'] ?? null,
                    'git_publickey' => $serverData['git_publickey'] ?? null,
                    'connection_status' => $serverData['connection_status'] ?? 'unknown',
                    'reboot_required' => $serverData['reboot_required'] ?? false,
                    'upgrade_required' => $serverData['upgrade_required'] ?? false,
                    'install_notes' => $serverData['install_notes'] ?? null,
                    'status' => $serverData['status'] ?? 'unknown',
                    'last_synced_at' => now(),
                ]
            );
            $count++;
        }

        return $count;
    }

    public function listSites(?int $serverId = null): array
    {
        $sites = [];
        $page = 1;
        $params = ['limit' => 100];

        if ($serverId) {
            $params['server_id'] = $serverId;
        }

        do {
            $params['page'] = $page;
            $response = $this->client()->get('/sites', $params);
            $data = $this->handleResponse($response, 'list sites');

            foreach ($data['data'] ?? [] as $site) {
                $sites[] = $site;
            }

            $hasMore = isset($data['pagination']['next']);
            $page++;
        } while ($hasMore);

        return $sites;
    }

    public function getSite(int $siteId): array
    {
        $response = $this->client()->get("/sites/{$siteId}");

        return $this->handleResponse($response, 'get site')['data'] ?? [];
    }

    public function createSite(SpinupWpServer $server, array $config): array
    {
        $defaults = config('services.spinupwp.site_defaults', []);

        $siteUser = $this->sanitizeSiteUser($config['domain']);

        $payload = [
            'server_id' => $server->spinup_id,
            'domain' => $config['domain'],
            'site_user' => $config['site_user'] ?? $siteUser,
            'installation_method' => $config['installation_method'] ?? 'wp',
            'php_version' => $config['php_version'] ?? $defaults['php_version'] ?? '8.3',
            'page_cache' => [
                'enabled' => $config['page_cache_enabled'] ?? $defaults['page_cache_enabled'] ?? true,
            ],
            'https' => [
                'enabled' => $config['https_enabled'] ?? $defaults['https_enabled'] ?? true,
            ],
        ];

        if (! empty($config['additional_domains'])) {
            $payload['additional_domains'] = $config['additional_domains'];
        }

        if (isset($config['database'])) {
            $payload['database'] = $config['database'];
        } else {
            $dbName = $this->sanitizeDatabaseName($config['domain']);
            $payload['database'] = [
                'name' => $dbName,
                'username' => $dbName,
            ];
        }

        if (isset($config['wordpress'])) {
            $payload['wordpress'] = $config['wordpress'];
        }

        if (! empty($config['git'])) {
            $payload['git'] = $config['git'];
        }

        if (! empty($config['deploy_script'])) {
            $payload['deploy_script'] = $config['deploy_script'];
        }

        $response = $this->client()->post('/sites', $payload);
        $data = $this->handleResponse($response, 'create site');

        return [
            'event_id' => $data['event_id'] ?? null,
            'site' => $data['data'] ?? [],
        ];
    }

    public function deleteSite(int $siteId, bool $deleteDatabase = true, bool $deleteBackups = false): int
    {
        $response = $this->client()->delete("/sites/{$siteId}", [
            'delete_database' => $deleteDatabase,
            'delete_backups' => $deleteBackups,
        ]);

        $data = $this->handleResponse($response, 'delete site');

        return $data['event_id'] ?? 0;
    }

    public function triggerGitDeploy(int $siteId): int
    {
        $response = $this->client()->post("/sites/{$siteId}/git/deploy");
        $data = $this->handleResponse($response, 'git deploy');

        return $data['event_id'] ?? 0;
    }

    public function purgePageCache(int $siteId): int
    {
        $response = $this->client()->post("/sites/{$siteId}/page-cache/purge");
        $data = $this->handleResponse($response, 'purge page cache');

        return $data['event_id'] ?? 0;
    }

    public function purgeObjectCache(int $siteId): int
    {
        $response = $this->client()->post("/sites/{$siteId}/object-cache/purge");
        $data = $this->handleResponse($response, 'purge object cache');

        return $data['event_id'] ?? 0;
    }

    public function getEvent(int $eventId): array
    {
        $response = $this->client()->get("/events/{$eventId}");

        return $this->handleResponse($response, 'get event')['data'] ?? [];
    }

    public function waitForEvent(int $eventId, int $maxWaitSeconds = 600, int $pollIntervalSeconds = 10): array
    {
        $startTime = time();

        while ((time() - $startTime) < $maxWaitSeconds) {
            $event = $this->getEvent($eventId);

            if (in_array($event['status'] ?? '', ['deployed', 'completed', 'failed'])) {
                return $event;
            }

            sleep($pollIntervalSeconds);
        }

        throw new \Exception("Event {$eventId} did not complete within {$maxWaitSeconds} seconds");
    }

    public function provisionSiteForProject(
        int $serverId,
        string $domain,
        string $siteTitle,
        string $adminEmail,
        ?string $gitRepo = null,
        ?string $deployScript = null,
        ?int $websiteProjectId = null
    ): SpinupWpSite {
        $server = SpinupWpServer::where('spinup_id', $serverId)
            ->orWhere('id', $serverId)
            ->firstOrFail();

        $adminPassword = Str::password(16);
        $dbPassword = Str::password(24);

        $config = [
            'domain' => $domain,
            'database' => [
                'name' => $this->sanitizeDatabaseName($domain),
                'username' => $this->sanitizeDatabaseName($domain),
                'password' => $dbPassword,
            ],
            'wordpress' => [
                'title' => $siteTitle,
                'admin_user' => 'admin',
                'admin_email' => $adminEmail,
                'admin_password' => $adminPassword,
            ],
        ];

        if ($gitRepo) {
            $config['git'] = [
                'repo' => $gitRepo,
                'branch' => 'main',
                'push_to_deploy' => true,
            ];

            if ($deployScript) {
                $config['deploy_script'] = $deployScript;
                $config['git']['always_run_deploy_script'] = true;
            }
        } elseif ($deployScript) {
            $config['deploy_script'] = $deployScript;
        }

        $result = $this->createSite($server, $config);

        $spinupSite = SpinupWpSite::create([
            'spinup_id' => $result['site']['id'],
            'spinup_server_id' => $server->id,
            'website_project_id' => $websiteProjectId,
            'domain' => $domain,
            'site_user' => $result['site']['site_user'] ?? $this->sanitizeSiteUser($domain),
            'php_version' => $result['site']['php_version'] ?? '8.3',
            'status' => SpinupWpSite::STATUS_PROVISIONING,
            'provision_event_id' => $result['event_id'],
            'wp_admin_user' => 'admin',
            'wp_admin_email' => $adminEmail,
            'wp_admin_password' => $adminPassword,
            'database_password' => $dbPassword,
            'git_config' => $config['git'] ?? null,
        ]);

        return $spinupSite;
    }

    public function checkProvisioningStatus(SpinupWpSite $site): SpinupWpSite
    {
        if (! $site->provision_event_id) {
            return $site;
        }

        $event = $this->getEvent($site->provision_event_id);

        if ($event['status'] === 'deployed') {
            $siteData = $this->getSite($site->spinup_id);
            $site->updateFromApi($siteData);
        } elseif ($event['status'] === 'failed') {
            $site->update([
                'status' => SpinupWpSite::STATUS_FAILED,
            ]);
        }

        return $site->fresh();
    }

    public function getDefaultServer(): ?SpinupWpServer
    {
        $configDefault = config('services.spinupwp.default_server_id');

        if ($configDefault) {
            $server = SpinupWpServer::where('spinup_id', $configDefault)
                ->orWhere('id', $configDefault)
                ->first();

            if ($server?->canHostSites()) {
                return $server;
            }
        }

        return SpinupWpServer::default()->first()
            ?? SpinupWpServer::available()->first();
    }

    protected function sanitizeSiteUser(string $domain): string
    {
        $user = preg_replace('/[^a-z0-9]/', '', strtolower(explode('.', $domain)[0]));

        return substr($user, 0, 32) ?: 'siteuser';
    }

    protected function sanitizeDatabaseName(string $domain): string
    {
        $name = preg_replace('/[^a-z0-9_]/', '_', strtolower(explode('.', $domain)[0]));

        return substr($name, 0, 64) ?: 'wordpress';
    }
}
