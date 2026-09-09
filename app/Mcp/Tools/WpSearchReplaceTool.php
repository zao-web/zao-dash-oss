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

class WpSearchReplaceTool extends Tool
{
    protected string $name = 'wp-search-replace';

    protected string $title = 'WordPress Search Replace';

    protected string $description = 'Run WP-CLI search-replace on a SpinupWP site via SSH. Useful for fixing URLs after domain changes or migrations.';

    public function __construct(protected SpinupWpSshService $sshService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'search' => 'required|string',
            'replace' => 'required|string',
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

        $search = $request->get('search');
        $replace = $request->get('replace');

        $args = [$search, $replace];

        if ($request->get('dry_run', false)) {
            $args[] = '--dry-run';
        }

        if ($request->get('all_tables', false)) {
            $args[] = '--all-tables';
        }

        $result = $this->sshService->runWpCli($site, 'search-replace', $args);

        if (! $result['success']) {
            return Response::structured([
                'success' => false,
                'error' => $result['error'] ?: 'Search-replace command failed',
                'site_domain' => $site->domain,
                'output' => $result['output'] ?? null,
            ]);
        }

        return Response::structured([
            'success' => true,
            'site_domain' => $site->domain,
            'search' => $search,
            'replace' => $replace,
            'dry_run' => $request->get('dry_run', false),
            'output' => $result['output'],
            'message' => $request->get('dry_run', false)
                ? "Dry run completed for {$site->domain}. No changes were made."
                : "Search-replace completed for {$site->domain}.",
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
            'search' => $schema->string()->description('The string to search for (e.g., old URL)')->required(),
            'replace' => $schema->string()->description('The string to replace with (e.g., new URL)')->required(),
            'site_id' => $schema->integer()->description('SpinupWP site ID'),
            'domain' => $schema->string()->description('Site domain (e.g., example.staging.example.com)'),
            'website_project_id' => $schema->integer()->description('Website project ID to find associated SpinupWP site'),
            'dry_run' => $schema->boolean()->description('Show what would be replaced without making changes (default: false)'),
            'all_tables' => $schema->boolean()->description('Search all tables, not just WordPress tables (default: false)'),
        ];
    }
}
