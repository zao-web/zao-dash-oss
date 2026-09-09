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

class WpUpdatePageTool extends Tool
{
    protected string $name = 'wp-update-page';

    protected string $title = 'WordPress Update Page';

    protected string $description = 'Update a WordPress page content via SSH/WP-CLI. Can update existing pages by slug or ID, or create new pages.';

    public function __construct(protected SpinupWpSshService $sshService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'site_id' => 'required_without_all:domain,website_project_id',
            'domain' => 'required_without_all:site_id,website_project_id',
            'website_project_id' => 'required_without_all:site_id,domain',
            'page_slug' => 'required_without:page_id|string',
            'page_id' => 'required_without:page_slug|integer',
            'content' => 'required|string',
            'title' => 'nullable|string',
            'status' => 'nullable|string|in:publish,draft,private',
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

        $pageId = $request->get('page_id');
        $pageSlug = $request->get('page_slug');
        $content = $request->get('content');
        $title = $request->get('title');
        $status = $request->get('status', 'publish');

        if (! $pageId && $pageSlug) {
            $lookupResult = $this->sshService->runWpCli($site, 'post list', [
                '--post_type=page',
                "--name={$pageSlug}",
                '--field=ID',
                '--format=csv',
            ]);

            if ($lookupResult['success'] && ! empty(trim($lookupResult['output']))) {
                $pageId = (int) trim($lookupResult['output']);
            }
        }

        $tempFile = '/tmp/wp_page_content_'.uniqid().'.html';
        $uploadResult = $this->sshService->uploadContent($site, $content, $tempFile);

        if (! $uploadResult['success']) {
            return Response::structured([
                'success' => false,
                'error' => 'Failed to upload content: '.($uploadResult['error'] ?? 'Unknown error'),
                'site_domain' => $site->domain,
            ]);
        }

        if ($pageId) {
            // Update existing page
            $args = [$tempFile];

            if ($title) {
                $args[] = "--post_title={$title}";
            }
            $args[] = "--post_status={$status}";

            $result = $this->sshService->runWpCli($site, "post update {$pageId}", $args);
            $action = 'updated';
        } else {
            // Create new page
            $args = [
                $tempFile,
                '--post_type=page',
                "--post_name={$pageSlug}",
                "--post_status={$status}",
                '--porcelain',
            ];

            if ($title) {
                $args[] = "--post_title={$title}";
            }

            $result = $this->sshService->runWpCli($site, 'post create', $args);
            $action = 'created';

            if ($result['success']) {
                $pageId = (int) trim($result['output']);
            }
        }

        // Clean up temp file
        $this->sshService->runCommand($site, "rm -f {$tempFile}");

        if (! $result['success']) {
            return Response::structured([
                'success' => false,
                'error' => $result['error'] ?: 'WP-CLI command failed',
                'site_domain' => $site->domain,
                'output' => $result['output'] ?? null,
            ]);
        }

        return Response::structured([
            'success' => true,
            'site_domain' => $site->domain,
            'action' => $action,
            'page_id' => $pageId,
            'page_slug' => $pageSlug,
            'message' => "Page {$action} successfully on {$site->domain}.",
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
            'page_id' => $schema->integer()->description('WordPress page ID to update (if known)'),
            'page_slug' => $schema->string()->description('Page slug to find/update or create'),
            'content' => $schema->string()->description('Page content in WordPress block format (HTML with block comments)')->required(),
            'title' => $schema->string()->description('Page title (optional for updates)'),
            'status' => $schema->string()->description('Page status: publish, draft, or private (default: publish)'),
        ];
    }
}
