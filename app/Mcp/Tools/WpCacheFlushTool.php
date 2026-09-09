<?php

namespace App\Mcp\Tools;

use App\Models\SpinupWpSite;
use App\Models\WebsiteProject;
use App\Services\SpinupWp\SpinupWpSshService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class WpCacheFlushTool extends Tool
{
    protected string $name = 'wp-cache-flush';

    protected string $title = 'WordPress Cache Flush';

    protected string $description = 'Flush WordPress and server-level caches on a SpinupWP site via SSH. Clears WP object cache, transients, and nginx page cache.';

    public function __construct(protected SpinupWpSshService $sshService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'site_id' => 'required_without_all:domain,website_project_id',
            'domain' => 'required_without_all:site_id,website_project_id',
            'website_project_id' => 'required_without_all:site_id,domain',
        ]);

        $site = $this->resolveSite($request);

        if (! $site) {
            return Response::structured([
                'success' => false,
                'error' => 'Could not find SpinupWP site. Provide site_id, domain, or website_project_id.',
            ]);
        }

        if (! $site->server?->ip_address) {
            return Response::structured([
                'success' => false,
                'error' => "Site '{$site->domain}' has no server with IP address configured.",
            ]);
        }

        $results = [];
        $allSuccess = true;

        $wpCacheResult = $this->sshService->runWpCli($site, 'cache flush', []);
        $results['wp_cache_flush'] = [
            'success' => $wpCacheResult['success'],
            'output' => $wpCacheResult['output'] ?? '',
            'error' => $wpCacheResult['error'] ?? '',
        ];
        if (! $wpCacheResult['success']) {
            $allSuccess = false;
        }

        $transientsResult = $this->sshService->runWpCli($site, 'transient delete', ['--all']);
        $results['transients_delete'] = [
            'success' => $transientsResult['success'],
            'output' => $transientsResult['output'] ?? '',
            'error' => $transientsResult['error'] ?? '',
        ];
        if (! $transientsResult['success']) {
            $allSuccess = false;
        }

        $nginxCacheClear = $this->sshService->runCommand(
            $site,
            'rm -rf /var/cache/spinupwp/$(whoami)/* 2>/dev/null || true'
        );
        $results['nginx_cache_clear'] = [
            'success' => true,
            'output' => $nginxCacheClear['output'] ?? 'Attempted nginx cache clear',
            'note' => 'Nginx cache clear attempted (may require elevated permissions)',
        ];

        $rewriteFlush = $this->sshService->runWpCli($site, 'rewrite flush', []);
        $results['rewrite_flush'] = [
            'success' => $rewriteFlush['success'],
            'output' => $rewriteFlush['output'] ?? '',
        ];

        return Response::structured([
            'success' => $allSuccess,
            'site_domain' => $site->domain,
            'results' => $results,
            'message' => $allSuccess
                ? "All caches flushed successfully for {$site->domain}."
                : "Some cache operations completed with errors for {$site->domain}. Check results for details.",
        ]);
    }

    protected function resolveSite(Request $request): ?SpinupWpSite
    {
        if ($siteId = $request->get('site_id')) {
            return SpinupWpSite::with('server')->find($siteId);
        }

        if ($domain = $request->get('domain')) {
            return SpinupWpSite::with('server')->where('domain', $domain)->first();
        }

        if ($projectId = $request->get('website_project_id')) {
            $site = SpinupWpSite::with('server')->where('website_project_id', $projectId)->first();

            if ($site) {
                return $site;
            }

            $project = WebsiteProject::find($projectId);
            if ($project?->staging_url) {
                $parsedUrl = parse_url($project->staging_url);
                $domain = $parsedUrl['host'] ?? null;

                if ($domain) {
                    return SpinupWpSite::with('server')->where('domain', $domain)->first();
                }
            }
        }

        return null;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->integer()->description('SpinupWP site ID'),
            'domain' => $schema->string()->description('Site domain (e.g., example.staging.example.com)'),
            'website_project_id' => $schema->integer()->description('Website project ID to find associated SpinupWP site'),
        ];
    }
}
