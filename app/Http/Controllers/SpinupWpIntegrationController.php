<?php

namespace App\Http\Controllers;

use App\Models\SpinupWpServer;
use App\Models\SpinupWpSite;
use App\Services\SpinupWp\SpinupWpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpinupWpIntegrationController extends Controller
{
    public function __construct(protected SpinupWpService $service) {}

    public function status(): JsonResponse
    {
        $servers = SpinupWpServer::with('sites')->get();

        return response()->json([
            'configured' => $this->service->isConfigured(),
            'connected' => $servers->isNotEmpty(),
            'servers' => $servers->map(fn ($s) => [
                'id' => $s->id,
                'spinup_id' => $s->spinup_id,
                'name' => $s->name,
                'ip_address' => $s->ip_address,
                'provider_name' => $s->provider_name,
                'status' => $s->status,
                'connection_status' => $s->connection_status,
                'is_default' => $s->is_default,
                'sites_count' => $s->sites->count(),
                'disk_usage_percent' => $s->getDiskUsagePercent(),
                'disk_available_gb' => $s->getDiskAvailableGb(),
                'last_synced_at' => $s->last_synced_at?->diffForHumans(),
            ]),
        ]);
    }

    public function syncServers(): JsonResponse
    {
        if (! $this->service->isConfigured()) {
            return response()->json([
                'error' => 'SpinupWP API token not configured',
            ], 422);
        }

        try {
            $count = $this->service->syncServers();

            return response()->json([
                'message' => "Synced {$count} server(s)",
                'synced' => $count,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to sync servers: '.$e->getMessage(),
            ], 500);
        }
    }

    public function setDefaultServer(Request $request): JsonResponse
    {
        $request->validate([
            'server_id' => 'required|integer|exists:spinup_wp_servers,id',
        ]);

        $server = SpinupWpServer::findOrFail($request->server_id);

        if (! $server->canHostSites()) {
            return response()->json([
                'error' => 'Server is not available to host sites',
            ], 422);
        }

        $server->setAsDefault();

        return response()->json([
            'message' => "Server '{$server->name}' set as default",
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'is_default' => true,
            ],
        ]);
    }

    public function listSites(Request $request): JsonResponse
    {
        $query = SpinupWpSite::with(['server', 'wordPressSite', 'websiteProject']);

        if ($request->has('server_id')) {
            $query->where('spinup_server_id', $request->server_id);
        }

        $sites = $query->orderBy('domain')->get();

        return response()->json([
            'sites' => $sites->map(fn ($site) => [
                'id' => $site->id,
                'spinup_id' => $site->spinup_id,
                'domain' => $site->domain,
                'status' => $site->status,
                'php_version' => $site->php_version,
                'https_enabled' => $site->https_enabled,
                'page_cache_enabled' => $site->page_cache_enabled,
                'server' => $site->server ? [
                    'id' => $site->server->id,
                    'name' => $site->server->name,
                ] : null,
                'wordpress_site' => $site->wordPressSite ? [
                    'id' => $site->wordPressSite->id,
                    'name' => $site->wordPressSite->name,
                ] : null,
                'website_project' => $site->websiteProject ? [
                    'id' => $site->websiteProject->id,
                    'name' => $site->websiteProject->name,
                ] : null,
                'provisioned_at' => $site->provisioned_at?->diffForHumans(),
                'last_synced_at' => $site->last_synced_at?->diffForHumans(),
            ]),
        ]);
    }

    public function refreshServer(SpinupWpServer $spinupServer): JsonResponse
    {
        if (! $this->service->isConfigured()) {
            return response()->json([
                'error' => 'SpinupWP API token not configured',
            ], 422);
        }

        try {
            $data = $this->service->getServer($spinupServer->spinup_id);
            $spinupServer->updateFromApi($data);

            return response()->json([
                'message' => 'Server refreshed',
                'server' => [
                    'id' => $spinupServer->id,
                    'name' => $spinupServer->name,
                    'status' => $spinupServer->status,
                    'connection_status' => $spinupServer->connection_status,
                    'last_synced_at' => $spinupServer->last_synced_at?->diffForHumans(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to refresh server: '.$e->getMessage(),
            ], 500);
        }
    }

    public function deleteServer(SpinupWpServer $spinupServer): JsonResponse
    {
        if ($spinupServer->sites()->exists()) {
            return response()->json([
                'error' => 'Cannot delete server with active sites. Delete sites first.',
            ], 422);
        }

        $name = $spinupServer->name;
        $spinupServer->delete();

        return response()->json([
            'message' => "Server '{$name}' removed from tracking",
        ]);
    }

    public function refreshSite(SpinupWpSite $spinupSite): JsonResponse
    {
        if (! $this->service->isConfigured()) {
            return response()->json([
                'error' => 'SpinupWP API token not configured',
            ], 422);
        }

        try {
            $data = $this->service->getSite($spinupSite->spinup_id);
            $spinupSite->updateFromApi($data);

            return response()->json([
                'message' => 'Site refreshed',
                'site' => [
                    'id' => $spinupSite->id,
                    'domain' => $spinupSite->domain,
                    'status' => $spinupSite->status,
                    'last_synced_at' => $spinupSite->last_synced_at?->diffForHumans(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to refresh site: '.$e->getMessage(),
            ], 500);
        }
    }

    public function deleteSite(SpinupWpSite $spinupSite, Request $request): JsonResponse
    {
        $request->validate([
            'delete_from_spinup' => 'boolean',
            'delete_database' => 'boolean',
        ]);

        $domain = $spinupSite->domain;

        if ($request->boolean('delete_from_spinup') && $this->service->isConfigured()) {
            try {
                $this->service->deleteSite(
                    $spinupSite->spinup_id,
                    $request->boolean('delete_database', true)
                );
            } catch (\Exception $e) {
                return response()->json([
                    'error' => 'Failed to delete site from SpinupWP: '.$e->getMessage(),
                ], 500);
            }
        }

        $spinupSite->delete();

        return response()->json([
            'message' => "Site '{$domain}' deleted",
        ]);
    }

    public function purgeCache(SpinupWpSite $spinupSite): JsonResponse
    {
        if (! $this->service->isConfigured()) {
            return response()->json([
                'error' => 'SpinupWP API token not configured',
            ], 422);
        }

        try {
            $this->service->purgePageCache($spinupSite->spinup_id);

            return response()->json([
                'message' => "Cache purged for '{$spinupSite->domain}'",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to purge cache: '.$e->getMessage(),
            ], 500);
        }
    }

    public function triggerDeploy(SpinupWpSite $spinupSite): JsonResponse
    {
        if (! $this->service->isConfigured()) {
            return response()->json([
                'error' => 'SpinupWP API token not configured',
            ], 422);
        }

        if (empty($spinupSite->git_config)) {
            return response()->json([
                'error' => 'Site does not have git deployment configured',
            ], 422);
        }

        try {
            $eventId = $this->service->triggerGitDeploy($spinupSite->spinup_id);

            return response()->json([
                'message' => "Deployment triggered for '{$spinupSite->domain}'",
                'event_id' => $eventId,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to trigger deploy: '.$e->getMessage(),
            ], 500);
        }
    }
}
