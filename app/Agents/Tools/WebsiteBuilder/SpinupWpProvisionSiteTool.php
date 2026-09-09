<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Events\WebsiteBuilderMessageReceived;
use App\Events\WebsiteBuilderStatusUpdated;
use App\Models\SpinupWpServer;
use App\Models\SpinupWpSite;
use App\Models\WebsiteProject;
use App\Services\SpinupWp\OllieDeployScriptGenerator;
use App\Services\SpinupWp\SpinupWpService;
use Illuminate\Support\Str;

class SpinupWpProvisionSiteTool extends BaseTool
{
    public function __construct(
        protected SpinupWpService $spinupWp
    ) {}

    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Provision SpinupWP Site';
    }

    public function description(): string
    {
        return 'Provision a new WordPress staging site via SpinupWP with Ollie theme pre-installed. Creates a fresh WordPress installation ready for page deployment.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'The Website Project ID to provision a site for',
                ],
                'domain' => [
                    'type' => 'string',
                    'description' => 'Domain name for the site (e.g., "client-staging.example.com"). If not provided, will generate from project name.',
                ],
                'server_id' => [
                    'type' => 'integer',
                    'description' => 'SpinupWP server ID to create site on. Uses default server if not specified.',
                ],
                'site_title' => [
                    'type' => 'string',
                    'description' => 'WordPress site title. Defaults to project name.',
                ],
                'admin_email' => [
                    'type' => 'string',
                    'description' => 'Admin email for WordPress. Required.',
                ],
                'install_ollie_pro' => [
                    'type' => 'boolean',
                    'description' => 'Whether to install Ollie Pro plugin (requires configured URL)',
                    'default' => false,
                ],
                'git_repo' => [
                    'type' => 'string',
                    'description' => 'Optional Git repository URL for deployment (e.g., "git@github.com:org/repo.git")',
                ],
                'wait_for_completion' => [
                    'type' => 'boolean',
                    'description' => 'Wait for site provisioning to complete before returning (can take 2-5 minutes)',
                    'default' => true,
                ],
            ],
            'required' => ['project_id', 'admin_email'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'required|integer|exists:website_projects,id',
            'domain' => 'nullable|string|max:253',
            'server_id' => 'nullable|integer',
            'site_title' => 'nullable|string|max:255',
            'admin_email' => 'required|email',
            'install_ollie_pro' => 'nullable|boolean',
            'git_repo' => 'nullable|string|max:500',
            'wait_for_completion' => 'nullable|boolean',
        ];
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function execute(array $params): array
    {
        if (! $this->spinupWp->isConfigured()) {
            return [
                'success' => false,
                'error' => 'SpinupWP is not configured. Add SPINUPWP_API_TOKEN to your environment.',
            ];
        }

        $project = WebsiteProject::findOrFail($params['project_id']);

        $existingSite = SpinupWpSite::where('website_project_id', $project->id)
            ->whereIn('status', [SpinupWpSite::STATUS_PROVISIONING, SpinupWpSite::STATUS_DEPLOYED])
            ->first();

        if ($existingSite) {
            return $this->handleExistingSite($existingSite, $project);
        }

        $server = $this->resolveServer($params['server_id'] ?? null);
        if (! $server) {
            return [
                'success' => false,
                'error' => 'No SpinupWP server available. Please sync servers first or specify a server_id.',
            ];
        }

        $domain = $params['domain'] ?? $this->generateDomain($project);
        $siteTitle = $params['site_title'] ?? $project->name;
        $adminEmail = $params['admin_email'];

        $this->broadcastProgress($project, 'provisioning', 'Creating WordPress site on SpinupWP...', 5);

        try {
            $deployScript = OllieDeployScriptGenerator::forWebsiteProject([
                'site_title' => $siteTitle,
                'admin_email' => $adminEmail,
                'install_ollie_pro' => $params['install_ollie_pro'] ?? false,
            ]);

            $spinupSite = $this->spinupWp->provisionSiteForProject(
                serverId: $server->spinup_id,
                domain: $domain,
                siteTitle: $siteTitle,
                adminEmail: $adminEmail,
                gitRepo: $params['git_repo'] ?? null,
                deployScript: $deployScript,
                websiteProjectId: $project->id
            );

            $this->broadcastProgress($project, 'provisioning', 'Site creation initiated. Waiting for provisioning...', 15);

            if ($params['wait_for_completion'] ?? true) {
                return $this->waitForProvisioning($spinupSite, $project);
            }

            return [
                'success' => true,
                'status' => 'provisioning',
                'message' => 'Site provisioning started. Check status with event ID.',
                'spinup_site_id' => $spinupSite->id,
                'spinup_event_id' => $spinupSite->provision_event_id,
                'domain' => $domain,
                'estimated_time' => '2-5 minutes',
            ];

        } catch (\Exception $e) {
            $this->broadcastError($project, $e->getMessage());

            return [
                'success' => false,
                'error' => 'Failed to provision site: '.$e->getMessage(),
            ];
        }
    }

    protected function handleExistingSite(SpinupWpSite $site, WebsiteProject $project): array
    {
        if ($site->isProvisioning()) {
            $site = $this->spinupWp->checkProvisioningStatus($site);

            if ($site->isProvisioned()) {
                return $this->buildSuccessResponse($site, $project);
            }

            return [
                'success' => true,
                'status' => 'provisioning',
                'message' => 'Site is still being provisioned. Please wait.',
                'spinup_site_id' => $site->id,
                'domain' => $site->domain,
            ];
        }

        return $this->buildSuccessResponse($site, $project);
    }

    protected function waitForProvisioning(SpinupWpSite $spinupSite, WebsiteProject $project): array
    {
        $maxAttempts = 60;
        $attempt = 0;
        $pollInterval = 10;

        while ($attempt < $maxAttempts) {
            $attempt++;
            sleep($pollInterval);

            $spinupSite = $this->spinupWp->checkProvisioningStatus($spinupSite);

            $progress = min(80, 15 + ($attempt * 2));
            $this->broadcastProgress(
                $project,
                'provisioning',
                "Provisioning in progress... ({$attempt}/{$maxAttempts})",
                $progress
            );

            if ($spinupSite->isProvisioned()) {
                $this->linkSiteToProject($spinupSite, $project);

                return $this->buildSuccessResponse($spinupSite, $project);
            }

            if ($spinupSite->hasFailed()) {
                $this->broadcastError($project, 'Site provisioning failed on SpinupWP');

                return [
                    'success' => false,
                    'error' => 'Site provisioning failed. Check SpinupWP dashboard for details.',
                    'spinup_site_id' => $spinupSite->id,
                ];
            }
        }

        return [
            'success' => false,
            'error' => 'Provisioning timed out after 10 minutes. Site may still be deploying.',
            'spinup_site_id' => $spinupSite->id,
            'spinup_event_id' => $spinupSite->provision_event_id,
        ];
    }

    protected function linkSiteToProject(SpinupWpSite $spinupSite, WebsiteProject $project): void
    {
        $wpSite = $spinupSite->createLinkedWordPressSite();

        $project->update([
            'wordpress_site_id' => $wpSite->id,
            'staging_url' => $spinupSite->url,
            'client_credentials' => [
                'wp_admin_url' => $spinupSite->admin_url,
                'wp_admin_user' => $spinupSite->wp_admin_user,
                'wp_admin_email' => $spinupSite->wp_admin_email,
            ],
        ]);
    }

    protected function buildSuccessResponse(SpinupWpSite $site, WebsiteProject $project): array
    {
        $this->broadcastProgress($project, 'provisioning', 'Site provisioned successfully!', 85);

        broadcast(new WebsiteBuilderMessageReceived(
            project: $project,
            content: "WordPress site is ready at {$site->url}\n\nAdmin: {$site->admin_url}\nUser: {$site->wp_admin_user}",
            role: 'assistant',
            metadata: [
                'action' => 'site_provisioned',
                'site_url' => $site->url,
                'admin_url' => $site->admin_url,
            ],
        ));

        return [
            'success' => true,
            'status' => 'deployed',
            'message' => 'WordPress site provisioned and ready for content deployment',
            'spinup_site_id' => $site->id,
            'wordpress_site_id' => $site->wordpress_site_id,
            'domain' => $site->domain,
            'site_url' => $site->url,
            'admin_url' => $site->admin_url,
            'credentials' => [
                'admin_user' => $site->wp_admin_user,
                'admin_email' => $site->wp_admin_email,
            ],
            'git_enabled' => $site->hasGitEnabled(),
            'git_deploy_url' => $site->git_deployment_url,
        ];
    }

    protected function resolveServer(?int $serverId): ?SpinupWpServer
    {
        if ($serverId) {
            return SpinupWpServer::where('spinup_id', $serverId)
                ->orWhere('id', $serverId)
                ->where('status', SpinupWpServer::STATUS_PROVISIONED)
                ->first();
        }

        return $this->spinupWp->getDefaultServer();
    }

    protected function generateDomain(WebsiteProject $project): string
    {
        $baseDomain = config('services.spinupwp.staging_domain', 'staging.example.com');
        $slug = Str::slug($project->name);
        $uniqueId = Str::random(6);

        return "{$slug}-{$uniqueId}.{$baseDomain}";
    }

    protected function broadcastProgress(WebsiteProject $project, string $phase, string $message, int $progress): void
    {
        broadcast(new WebsiteBuilderStatusUpdated(
            project: $project,
            status: 'deploying',
            phase: $phase,
            message: $message,
            phaseProgress: $progress,
            progress: $progress,
        ));
    }

    protected function broadcastError(WebsiteProject $project, string $error): void
    {
        broadcast(new WebsiteBuilderMessageReceived(
            project: $project,
            content: "Site provisioning error: {$error}",
            role: 'assistant',
            metadata: ['action' => 'provision_error', 'error' => $error],
        ));

        broadcast(new WebsiteBuilderStatusUpdated(
            project: $project,
            status: 'failed',
            phase: 'provisioning',
            message: $error,
            phaseProgress: 0,
            progress: 0,
        ));
    }
}
