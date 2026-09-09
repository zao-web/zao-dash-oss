<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\GitHubInstallation;
use App\Models\NotionConnection;
use App\Models\PmConnection;
use App\Models\Project;
use App\Models\QuickBooksConnection;
use App\Models\SlackWorkspace;
use App\Models\SpinupWpServer;
use App\Models\WordPressSite;
use App\Services\SpinupWp\SpinupWpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function integrations(Request $request): Response
    {
        $user = $request->user();

        // Google status
        $googleCredential = $user->googleCredential;
        $google = [
            'connected' => $googleCredential !== null,
            'email' => $googleCredential?->email,
            'expires_at' => $googleCredential?->expires_at?->toISOString(),
            'scopes' => $googleCredential?->scopes ?? [],
        ];

        // Slack status
        $slackWorkspaces = SlackWorkspace::all();
        $slack = [
            'connected' => $slackWorkspaces->isNotEmpty(),
            'workspaces' => $slackWorkspaces->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->workspace_name,
                'workspace_id' => $w->workspace_id,
            ]),
        ];

        // GitHub App status (for org-level access)
        $githubInstallations = GitHubInstallation::all();
        $github = [
            'connected' => $githubInstallations->isNotEmpty(),
            'installations' => $githubInstallations->map(fn ($i) => [
                'id' => $i->id,
                'account_login' => $i->account_login,
                'account_type' => $i->account_type,
                'repos_count' => $i->repos()->count(),
            ]),
            'app_slug' => config('services.github.app_slug'),
        ];

        // GitHub User OAuth status (for personal repo access)
        $githubCredential = $user->githubCredential;
        $githubUser = [
            'connected' => $githubCredential !== null,
            'username' => $githubCredential?->username,
            'email' => $githubCredential?->email,
            'avatar_url' => $githubCredential?->avatar_url,
            'scopes' => $githubCredential?->scopes ?? [],
            'expires_at' => $githubCredential?->expires_at?->toISOString(),
        ];

        // Harvest status
        $harvestCredential = $user->harvestCredential;
        $harvest = [
            'connected' => $harvestCredential !== null,
            'account_name' => $harvestCredential?->account_name,
            'expires_at' => $harvestCredential?->expires_at?->toISOString(),
        ];

        // Notion status
        $notionConnections = NotionConnection::all();
        $notion = [
            'connected' => $notionConnections->isNotEmpty(),
            'workspaces' => $notionConnections->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->workspace_name,
                'icon' => $c->workspace_icon,
                'pages_count' => $c->pages()->count(),
            ]),
        ];

        // WordPress status
        $wordpressSites = WordPressSite::all();
        $wordpress = [
            'connected' => $wordpressSites->isNotEmpty(),
            'sites' => $wordpressSites->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'url' => $s->url,
                'mcp_enabled' => $s->mcp_enabled,
                'posts_count' => $s->posts()->count(),
                'last_synced_at' => $s->last_synced_at?->diffForHumans(),
            ]),
        ];

        // QuickBooks status
        $qboConnections = QuickBooksConnection::all();
        $quickbooks = [
            'connected' => $qboConnections->isNotEmpty(),
            'companies' => $qboConnections->map(fn ($c) => [
                'id' => $c->id,
                'company_name' => $c->company_name,
                'realm_id' => $c->realm_id,
                'last_synced_at' => $c->last_synced_at?->diffForHumans(),
            ]),
        ];

        // LinkedIn status
        $linkedInCredential = $user->linkedInCredential;
        $linkedin = [
            'connected' => $linkedInCredential !== null,
            'name' => $linkedInCredential?->name,
            'email' => $linkedInCredential?->email,
            'headline' => $linkedInCredential?->headline,
            'profile_url' => $linkedInCredential?->profile_url,
            'organization_name' => $linkedInCredential?->organization_name,
            'expires_at' => $linkedInCredential?->token_expires_at?->toISOString(),
        ];

        // X (Twitter) status - supports multiple accounts
        $xCredentials = $user->xCredentials;
        $x = [
            'connected' => $xCredentials->isNotEmpty(),
            'accounts' => $xCredentials->map(fn ($cred) => [
                'id' => $cred->id,
                'username' => $cred->username,
                'name' => $cred->name,
                'account_type' => $cred->account_type ?? 'personal',
                'verified' => $cred->verified,
                'followers_count' => $cred->followers_count,
                'expires_at' => $cred->token_expires_at?->toISOString(),
            ]),
            // Backwards compatibility
            'username' => $xCredentials->first()?->username,
            'name' => $xCredentials->first()?->name,
            'verified' => $xCredentials->first()?->verified,
            'followers_count' => $xCredentials->first()?->followers_count,
            'expires_at' => $xCredentials->first()?->token_expires_at?->toISOString(),
        ];

        // ClickUp status (PM tool)
        $clickUpConnections = PmConnection::where('platform', PmConnection::PLATFORM_CLICKUP)
            ->where('user_id', $user->id)
            ->with('client')
            ->get();
        $clickup = [
            'connected' => $clickUpConnections->isNotEmpty(),
            'connections' => $clickUpConnections->map(fn ($c) => [
                'id' => $c->id,
                'workspace_name' => $c->workspace_name,
                'workspace_id' => $c->workspace_id,
                'client_id' => $c->client_id,
                'client_name' => $c->client?->name,
                'sources_count' => $c->taskSources()->count(),
                'last_synced_at' => $c->last_synced_at?->diffForHumans(),
            ]),
        ];

        // SpinupWP status (WordPress hosting)
        $spinupWpService = app(SpinupWpService::class);
        $spinupServers = SpinupWpServer::with('sites')->get();
        $spinupwp = [
            'configured' => $spinupWpService->isConfigured(),
            'connected' => $spinupServers->isNotEmpty(),
            'servers' => $spinupServers->map(fn ($s) => [
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
            'default_server_id' => config('services.spinupwp.default_server_id'),
            'staging_domain' => config('services.spinupwp.staging_domain'),
        ];

        // Get clients and projects for ClickUp configuration
        $clients = Client::orderBy('name')->get(['id', 'name']);
        $projects = Project::with('client:id,name')->orderBy('name')->get(['id', 'name', 'client_id']);

        return Inertia::render('Settings/Integrations', [
            'integrations' => [
                'google' => $google,
                'slack' => $slack,
                'github' => $github,
                'github_user' => $githubUser,
                'harvest' => $harvest,
                'notion' => $notion,
                'wordpress' => $wordpress,
                'quickbooks' => $quickbooks,
                'linkedin' => $linkedin,
                'x' => $x,
                'clickup' => $clickup,
                'spinupwp' => $spinupwp,
            ],
            'clients' => $clients,
            'projects' => $projects,
        ]);
    }

    public function allIntegrationStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'google' => [
                'connected' => $user->googleCredential !== null,
            ],
            'slack' => [
                'connected' => SlackWorkspace::exists(),
            ],
            'github' => [
                'connected' => GitHubInstallation::exists(),
            ],
            'github_user' => [
                'connected' => $user->githubCredential !== null,
            ],
            'harvest' => [
                'connected' => $user->harvestCredential !== null,
            ],
            'notion' => [
                'connected' => NotionConnection::exists(),
            ],
            'wordpress' => [
                'connected' => WordPressSite::exists(),
            ],
            'quickbooks' => [
                'connected' => QuickBooksConnection::exists(),
            ],
            'linkedin' => [
                'connected' => $user->linkedInCredential !== null,
            ],
            'x' => [
                'connected' => $user->xCredential !== null,
            ],
            'clickup' => [
                'connected' => PmConnection::where('platform', PmConnection::PLATFORM_CLICKUP)
                    ->where('user_id', $user->id)
                    ->exists(),
            ],
            'spinupwp' => [
                'configured' => app(SpinupWpService::class)->isConfigured(),
                'connected' => SpinupWpServer::exists(),
            ],
        ]);
    }
}
