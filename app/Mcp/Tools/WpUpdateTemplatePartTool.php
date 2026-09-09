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

class WpUpdateTemplatePartTool extends Tool
{
    protected string $name = 'wp-update-template-part';

    protected string $title = 'WordPress Update Template Part';

    protected string $description = 'Update a WordPress FSE template part (header, footer, etc.) via SSH/WP-CLI. Supports Ollie theme and child themes.';

    public function __construct(protected SpinupWpSshService $sshService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'site_id' => 'required_without_all:domain,website_project_id',
            'domain' => 'required_without_all:site_id,website_project_id',
            'website_project_id' => 'required_without_all:site_id,domain',
            'slug' => 'required|string|in:header,footer,sidebar',
            'content' => 'required|string',
            'theme' => 'nullable|string',
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

        $slug = $request->get('slug');
        $content = $request->get('content');
        $theme = $request->get('theme', 'ollie');

        // Use file-based approach for more reliable FSE template part updates
        // This writes directly to the child theme's parts folder
        $result = $this->sshService->writeTemplatePartFile($site, $slug, $content, $theme);

        if (! $result['success']) {
            return Response::structured([
                'success' => false,
                'error' => $result['error'] ?: 'Failed to update template part',
                'site_domain' => $site->domain,
                'output' => $result['output'] ?? null,
            ]);
        }

        return Response::structured([
            'success' => true,
            'site_domain' => $site->domain,
            'template_part' => $slug,
            'theme' => $theme,
            'message' => "Template part '{$slug}' updated successfully on {$site->domain}.",
            'output' => $result['output'] ?? null,
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
            'slug' => $schema->string()->description('Template part slug: header, footer, or sidebar')->required(),
            'content' => $schema->string()->description('Template part content in WordPress block format (HTML with block comments)')->required(),
            'theme' => $schema->string()->description('Theme slug (default: ollie). Use ollie-child for child theme template parts.'),
        ];
    }
}
