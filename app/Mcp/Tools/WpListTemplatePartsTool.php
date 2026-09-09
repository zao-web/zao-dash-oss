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

class WpListTemplatePartsTool extends Tool
{
    protected string $name = 'wp-list-template-parts';

    protected string $title = 'WordPress List Template Parts';

    protected string $description = 'List all template parts in the database and files for debugging FSE template issues.';

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

        $dbResult = $this->sshService->runCommand(
            $site,
            'cd ~/files && wp post list --post_type=wp_template_part --format=json --fields=ID,post_name,post_title,post_status'
        );

        $dbParts = [];
        if ($dbResult['success'] && ! empty($dbResult['output'])) {
            $dbParts = json_decode($dbResult['output'], true) ?? [];
        }

        $fileResult = $this->sshService->runCommand(
            $site,
            'ls -la ~/files/wp-content/themes/ollie-child/parts/ 2>/dev/null || echo "No child theme parts directory"'
        );

        $parentFileResult = $this->sshService->runCommand(
            $site,
            'ls -la ~/files/wp-content/themes/ollie/parts/ 2>/dev/null || echo "No parent theme parts directory"'
        );

        $themeResult = $this->sshService->runCommand(
            $site,
            'cd ~/files && wp theme list --status=active --format=json --fields=name,status'
        );

        $headerContent = $this->sshService->runCommand(
            $site,
            'cat ~/files/wp-content/themes/ollie-child/parts/header.html 2>/dev/null || echo "File not found"'
        );

        return Response::structured([
            'success' => true,
            'site_domain' => $site->domain,
            'active_theme' => json_decode($themeResult['output'] ?? '[]', true),
            'database_template_parts' => $dbParts,
            'child_theme_parts_dir' => $fileResult['output'] ?? 'Error reading',
            'parent_theme_parts_dir' => $parentFileResult['output'] ?? 'Error reading',
            'child_header_content_preview' => substr($headerContent['output'] ?? '', 0, 500),
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
