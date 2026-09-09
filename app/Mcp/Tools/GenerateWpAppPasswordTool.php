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

class GenerateWpAppPasswordTool extends Tool
{
    protected string $name = 'generate-wp-app-password';

    protected string $title = 'Generate WordPress Application Password';

    protected string $description = 'Generate a WordPress application password for a SpinupWP site via SSH/WP-CLI. This enables REST API access for the website builder tools.';

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
                'error' => "Site '{$site->domain}' has no server with IP address configured. Sync servers first.",
            ]);
        }

        if (! $site->wp_admin_user) {
            $userResult = $this->sshService->discoverAdminUser($site);

            if (! $userResult['success']) {
                return Response::structured([
                    'success' => false,
                    'error' => "Failed to discover WordPress admin user: {$userResult['error']}",
                ]);
            }

            $site->refresh();
        }

        $appName = $request->get('app_name', 'Zao Dash API');
        $result = $this->sshService->generateApplicationPassword($site, $appName);

        if (! $result['success']) {
            return Response::structured([
                'success' => false,
                'error' => $result['error'],
                'site_domain' => $site->domain,
            ]);
        }

        return Response::structured([
            'success' => true,
            'site_id' => $site->id,
            'site_domain' => $site->domain,
            'wordpress_site_id' => $result['wordpress_site']->id,
            'username' => $site->wp_admin_user,
            'message' => "Application password generated for {$site->domain}. WordPress REST API is now accessible.",
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
            'app_name' => $schema->string()->description('Name for the application password (default: "Zao Dash API")'),
        ];
    }
}
