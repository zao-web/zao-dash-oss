<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\GoogleIntegrationController;
use App\Http\Controllers\IdealCustomerProfileController;
use App\Http\Controllers\KpiController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RfpController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\VaultController;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\HarvestInvoice;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\VaultSecret;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Auth Routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login')->middleware('guest');
Route::post('/login', [AuthController::class, 'login'])->middleware('guest');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Webhook Routes (no auth - uses token validation)
Route::prefix('webhooks')->group(function () {
    Route::post('/agents/{agent:slug}', [App\Http\Controllers\WebhookController::class, 'trigger'])->name('webhooks.trigger');
    Route::get('/status/{runId}', [App\Http\Controllers\WebhookController::class, 'status'])->name('webhooks.status');

    // Google Push Notifications
    Route::post('/google/gmail', [App\Http\Controllers\GoogleWebhookController::class, 'gmail'])->name('webhooks.google.gmail');
    Route::post('/google/calendar', [App\Http\Controllers\GoogleWebhookController::class, 'calendar'])->name('webhooks.google.calendar');

    // Slack Events API
    Route::post('/slack/events', [App\Http\Controllers\SlackWebhookController::class, 'events'])->name('webhooks.slack.events');
    Route::post('/slack/slash', [App\Http\Controllers\SlackWebhookController::class, 'slashCommand'])->name('webhooks.slack.slash');
    Route::post('/slack/interactivity', [App\Http\Controllers\SlackWebhookController::class, 'interactivity'])->name('webhooks.slack.interactivity');

    // GitHub Webhooks
    Route::post('/github', [App\Http\Controllers\GitHubWebhookController::class, 'handle'])->name('webhooks.github');

    // Harvest Webhooks
    Route::post('/harvest', [App\Http\Controllers\HarvestWebhookController::class, 'handle'])->name('webhooks.harvest');

    // QuickBooks Webhooks
    Route::post('/quickbooks', [App\Http\Controllers\QuickBooksWebhookController::class, 'handle'])->name('webhooks.quickbooks');

    // WordPress Webhooks
    Route::post('/wordpress', [App\Http\Controllers\WordPressWebhookController::class, 'handle'])->name('webhooks.wordpress');

    // Wise Webhooks (transfer status updates)
    Route::post('/wise', [App\Http\Controllers\WiseController::class, 'webhook'])->name('webhooks.wise');

    // Gravity Forms Webhooks (leads)
    Route::post('/gravity-forms', [App\Http\Controllers\GravityFormsWebhookController::class, 'handle'])->name('webhooks.gravity-forms');

    // PayPal Webhooks (payment notifications)
    Route::post('/paypal', [App\Http\Controllers\PayPalWebhookController::class, 'handle'])->name('webhooks.paypal');


});

Route::get('/debug/invoice/{invoice}/paypal', function (App\Models\Invoice $invoice, App\Services\PayPal\PayPalService $paypalService) {
    if (! request()->query('token') || ! hash_equals($invoice->public_token, request()->query('token'))) {
        abort(403, 'Invalid token');
    }

    $invoice->load(['client', 'lines']);

    $debug = [
        'invoice' => [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'amount_due' => $invoice->amount_due,
            'paypal_invoice_id' => $invoice->paypal_invoice_id,
        ],
        'paypal_config' => [
            'is_configured' => $paypalService->isConfigured(),
            'client_id_set' => ! empty(config('services.paypal.client_id')),
            'client_secret_set' => ! empty(config('services.paypal.client_secret')),
            'mode' => config('services.paypal.mode'),
        ],
        'line_items' => $invoice->lines->map(fn ($line) => [
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'amount' => $line->amount,
            'will_be_sent_to_paypal' => $line->quantity > 0,
        ])->toArray(),
    ];

    if ($paypalService->isConfigured() && ! $invoice->paypal_invoice_id) {
        try {
            $debug['paypal_creation_attempt'] = 'Attempting to create PayPal invoice...';
            $paypalService->createInvoice($invoice);
            $invoice->refresh();
            $debug['paypal_creation_result'] = 'SUCCESS';
            $debug['invoice']['paypal_invoice_id'] = $invoice->paypal_invoice_id;
        } catch (\Exception $e) {
            $debug['paypal_creation_result'] = 'FAILED';
            $debug['paypal_creation_error'] = $e->getMessage();
        }
    }

    if ($invoice->paypal_invoice_id) {
        $paymentLink = $paypalService->getPaymentLink($invoice);
        $debug['payment_link'] = $paymentLink ?? 'NULL - check logs for details';
    }

    return response()->json($debug, 200, [], JSON_PRETTY_PRINT);
})->name('debug.invoice.paypal');

// Public Invoice Routes (no auth - uses token verification)
Route::prefix('invoice')->group(function () {
    Route::get('/{invoice}', [App\Http\Controllers\InvoiceController::class, 'publicView'])
        ->name('invoices.public');
    Route::get('/{invoice}/pdf', [App\Http\Controllers\InvoiceController::class, 'publicDownloadPdf'])
        ->name('invoices.public.pdf');
});

// Protected Routes
Route::middleware('auth')->group(function () {

    // Dashboard / Command
    Route::get('/', function () {
        $activeProjects = Project::where('status', 'active')->count();
        $clientHealthAvg = Client::where('status', 'active')->avg('health_score') ?? 0;
        $runningAgents = AgentRun::where('status', 'running')->count();
        $pendingApprovals = ApprovalRequest::where('status', 'pending')->count();

        $monthStart = now()->startOfMonth();
        $today = now();
        $lastMonthStart = now()->subMonth()->startOfMonth();
        $lastMonthEnd = now()->subMonth()->endOfMonth();

        $revenueMtd = (float) HarvestInvoice::where('state', 'paid')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$monthStart, $today])
            ->sum('amount');

        $lastMonthRevenue = (float) HarvestInvoice::where('state', 'paid')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$lastMonthStart, $lastMonthEnd])
            ->sum('amount');

        $revenueChangePct = $lastMonthRevenue > 0
            ? round((($revenueMtd - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1)
            : 0;

        $hoursTrackedMtd = (float) TimeEntry::whereBetween('spent_date', [$monthStart, $today])->sum('hours');

        $recentAgentRuns = AgentRun::with('agent')
            ->orderBy('created_at', 'desc')
            ->limit(6)
            ->get()
            ->map(fn ($run) => [
                'id' => $run->id,
                'agent_name' => $run->agent->name,
                'agent_slug' => $run->agent->slug,
                'status' => $run->status,
                'trigger' => $run->task,
                'cost_usd' => $run->cost_usd,
                'started_at' => $run->started_at?->diffForHumans(),
                'completed_at' => $run->completed_at?->diffForHumans(),
                'created_at' => $run->created_at->diffForHumans(),
            ]);

        $pendingApprovalsList = ApprovalRequest::with(['agentRun.agent'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->limit(4)
            ->get()
            ->map(fn ($approval) => [
                'id' => $approval->id,
                'action_type' => $approval->action_type,
                'summary' => $approval->description,
                'risk_level' => $approval->risk_level ?? 'medium',
                'agent_name' => $approval->agentRun?->agent?->name ?? 'Unknown Agent',
                'expires_at' => $approval->expires_at?->diffForHumans(),
                'created_at' => $approval->created_at->diffForHumans(),
            ]);

        $clients = Client::where('status', 'active')
            ->orderBy('health_score', 'desc')
            ->limit(5)
            ->get(['id', 'name', 'slug', 'health_score', 'status']);

        // Active strategic goal
        $activeGoal = \App\Models\StrategicGoal::where('status', 'active')->first();
        $goalData = null;
        if ($activeGoal) {
            $goalData = [
                'id' => $activeGoal->id,
                'name' => $activeGoal->name,
                'fiscal_year' => $activeGoal->fiscal_year,
                'revenue_target' => $activeGoal->revenue_target,
                'revenue_actual' => $activeGoal->yearlyPeriod?->revenue_actual ?? 0,
                'progress_percent' => $activeGoal->progress_percent,
                'is_on_track' => $activeGoal->is_on_track,
                'time_elapsed_percent' => $activeGoal->time_elapsed_percent,
            ];
        }

        // Current week plan
        $currentPlan = \App\Models\WeeklyPlan::current();
        $planData = null;
        if ($currentPlan) {
            $planData = [
                'id' => $currentPlan->id,
                'week_label' => $currentPlan->week_label,
                'status' => $currentPlan->status,
                'focus_areas' => $currentPlan->focus_areas ?? [],
                'progress_percent' => $currentPlan->progress_percent,
                'items_total' => $currentPlan->items()->count(),
                'items_completed' => $currentPlan->completedItems()->count(),
            ];
        }

        // Agent checkins (Business Strategist and similar agents with actionable output)
        $agentCheckins = AgentRun::with('agent')
            ->whereHas('agent', fn ($q) => $q->whereIn('slug', ['business-strategist', 'c-f-o', 'client-health-monitor']))
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($run) {
                $output = is_array($run->output) ? $run->output : [];

                // Extract action items from various possible output formats
                $actionItems = [];
                $alerts = [];
                $recommendations = [];

                // Check for Human Actions Required / action_items
                $humanActions = $output['Human Actions Required'] ?? $output['human_actions'] ?? $output['action_items'] ?? [];
                if (is_array($humanActions)) {
                    foreach ($humanActions as $action) {
                        if (is_string($action)) {
                            $actionItems[] = ['type' => 'task', 'title' => $action];
                        } elseif (is_array($action)) {
                            $actionItems[] = [
                                'type' => $action['type'] ?? 'task',
                                'title' => $action['title'] ?? $action['description'] ?? $action['action'] ?? 'Action required',
                                'description' => $action['description'] ?? $action['details'] ?? null,
                                'agent_slug' => $action['agent_slug'] ?? $action['agent'] ?? null,
                                'metadata' => $action['metadata'] ?? null,
                            ];
                        }
                    }
                }

                // Extract alerts
                $alertData = $output['alerts'] ?? $output['Alerts'] ?? [];
                if (is_array($alertData)) {
                    foreach ($alertData as $alert) {
                        $alerts[] = is_string($alert) ? $alert : ($alert['message'] ?? $alert['text'] ?? json_encode($alert));
                    }
                }

                // Extract recommendations
                $recData = $output['recommendations'] ?? $output['Recommendations'] ?? [];
                if (is_array($recData)) {
                    foreach ($recData as $rec) {
                        $recommendations[] = is_string($rec) ? $rec : ($rec['text'] ?? $rec['recommendation'] ?? json_encode($rec));
                    }
                }

                // Build summary from output
                $summary = $output['summary'] ?? $output['Summary'] ?? null;
                if (! $summary && isset($output['Situation'])) {
                    $summary = $output['Situation'];
                }
                if (! $summary && isset($output['status'])) {
                    $summary = $output['status'];
                }

                return [
                    'id' => $run->id,
                    'agent_name' => $run->agent->name,
                    'agent_slug' => $run->agent->slug,
                    'mode' => $output['mode'] ?? 'checkin',
                    'status' => $run->status,
                    'created_at' => $run->created_at->diffForHumans(),
                    'summary' => $summary,
                    'action_items' => $actionItems,
                    'alerts' => $alerts,
                    'recommendations' => $recommendations,
                    'weekly_plan_id' => $output['weekly_plan_id'] ?? null,
                ];
            });

        return Inertia::render('Dashboard', [
            'kpis' => [
                'revenue_mtd' => $revenueMtd,
                'revenue_change_pct' => $revenueChangePct,
                'active_projects' => $activeProjects,
                'client_health_avg' => round($clientHealthAvg, 1),
                'hours_tracked_mtd' => round($hoursTrackedMtd, 1),
                'pending_approvals' => $pendingApprovals,
                'running_agents' => $runningAgents,
                'total_agents' => Agent::count(),
            ],
            'recentAgentRuns' => $recentAgentRuns,
            'pendingApprovals' => $pendingApprovalsList,
            'clients' => $clients,
            'activeGoal' => $goalData,
            'currentPlan' => $planData,
            'agentCheckins' => $agentCheckins,
        ]);
    })->name('dashboard');

    // Projects
    Route::get('/projects', function () {
        $showArchived = request()->boolean('archived');

        $projectsQuery = Project::with('client')
            ->withCount(['tasks', 'tasks as completed_tasks_count' => function ($query) {
                $query->where('status', 'completed');
            }]);

        // Default to active/in_progress/completed, exclude archived unless requested
        if (! $showArchived) {
            $projectsQuery->whereNotIn('status', ['archived']);
        } else {
            $projectsQuery->where('status', 'archived');
        }

        $projects = $projectsQuery->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => $project->status,
                'type' => $project->type,
                'budget' => $project->budget,
                'client' => $project->client ? [
                    'id' => $project->client->id,
                    'name' => $project->client->name,
                    'slug' => $project->client->slug,
                ] : null,
                'tasks_count' => $project->tasks_count,
                'completed_tasks_count' => $project->completed_tasks_count,
                'created_at' => $project->created_at->format('M d, Y'),
            ]);

        // Group projects by client
        $projectsByClient = $projects->groupBy(fn ($project) => $project['client']['name'] ?? 'No Client')
            ->sortKeys()
            ->map(fn ($clientProjects, $clientName) => [
                'client_name' => $clientName,
                'client_slug' => $clientProjects->first()['client']['slug'] ?? null,
                'projects' => $clientProjects->values()->all(),
            ])
            ->values()
            ->all();

        return Inertia::render('Projects/Index', [
            'projectsByClient' => $projectsByClient,
            'projects' => $projects, // Keep for backward compatibility
            'showArchived' => $showArchived,
            'stats' => [
                'total' => Project::count(),
                'active' => Project::where('status', 'active')->count(),
                'archived' => Project::where('status', 'archived')->count(),
                'completed' => Project::where('status', 'completed')->count(),
                'total_budget' => Project::whereNotIn('status', ['archived'])->sum('budget'),
            ],
            'clients' => Client::where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'slug']),
        ]);
    })->name('projects.index');

    // Meta Ads
    Route::get('/meta-ads', [App\Http\Controllers\MetaAdsController::class, 'index'])->name('meta-ads.index');
    Route::get('/meta-ads/campaigns/create', [App\Http\Controllers\MetaAdsController::class, 'create'])->name('meta-ads.campaigns.create');
    Route::post('/meta-ads/campaigns', [App\Http\Controllers\MetaAdsController::class, 'store'])->name('meta-ads.campaigns.store');
    Route::get('/meta-ads/campaigns/{campaign}', [App\Http\Controllers\MetaAdsController::class, 'show'])->name('meta-ads.campaigns.show');
    Route::post('/meta-ads/campaigns/{campaign}/approve', [App\Http\Controllers\MetaAdsController::class, 'approve'])->name('meta-ads.campaigns.approve');
    Route::post('/meta-ads/campaigns/{campaign}/pause', [App\Http\Controllers\MetaAdsController::class, 'pause'])->name('meta-ads.campaigns.pause');
    Route::post('/meta-ads/campaigns/{campaign}/resume', [App\Http\Controllers\MetaAdsController::class, 'resume'])->name('meta-ads.campaigns.resume');
    Route::delete('/meta-ads/campaigns/{campaign}', [App\Http\Controllers\MetaAdsController::class, 'destroy'])->name('meta-ads.campaigns.destroy');

    // Clients
    Route::get('/clients', function () {
        $search = request()->input('search');
        $statusFilter = request()->input('status', 'active');

        $clientsQuery = Client::with('contacts')
            ->withCount(['projects', 'projects as active_projects_count' => function ($query) {
                $query->where('status', 'active');
            }]);

        // Status filter
        if ($statusFilter && $statusFilter !== 'all') {
            if ($statusFilter === 'archived') {
                $clientsQuery->whereIn('status', ['archived', 'inactive']);
            } else {
                $clientsQuery->where('status', $statusFilter);
            }
        }

        // Search filter
        if ($search) {
            $clientsQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slack_channel', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('contacts', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $clients = $clientsQuery->orderBy('health_score', 'desc')
            ->get()
            ->map(fn ($client) => [
                'id' => $client->id,
                'name' => $client->name,
                'slug' => $client->slug,
                'description' => $client->description,
                'health_score' => $client->health_score,
                'status' => $client->status,
                'website' => $client->website,
                'slack_channel' => $client->slack_channel,
                'billing_email' => $client->billing_email,
                'billing_cc_emails' => $client->billing_cc_emails,
                'contacts' => $client->contacts->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'email' => $c->email,
                    'role' => $c->role,
                    'is_primary' => $c->is_primary,
                ]),
                'projects_count' => $client->projects_count,
                'active_projects_count' => $client->active_projects_count,
            ]);

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'showArchived' => $statusFilter === 'archived',
            'filters' => [
                'search' => $search,
                'status' => $statusFilter,
            ],
            'stats' => [
                'total' => Client::count(),
                'active' => Client::where('status', 'active')->count(),
                'archived' => Client::whereIn('status', ['archived', 'inactive', 'churned'])->count(),
                'avg_health' => Client::where('status', 'active')->avg('health_score') ?? 0,
            ],
        ]);
    })->name('clients.index');

    // Bulk update clients
    Route::post('/clients/bulk-update', function () {
        $validated = request()->validate([
            'client_ids' => 'required|array',
            'client_ids.*' => 'exists:clients,id',
            'status' => 'required|in:active,inactive,archived,prospect,churned',
        ]);

        Client::whereIn('id', $validated['client_ids'])
            ->update(['status' => $validated['status']]);

        return back()->with('success', count($validated['client_ids']).' clients updated.');
    })->name('clients.bulk-update');

    // Team
    Route::get('/team', function () {
        $users = User::withCount(['tasks', 'tasks as completed_tasks_count' => function ($query) {
            $query->where('status', 'completed');
        }])
            ->orderByRaw("CASE role WHEN 'owner' THEN 1 WHEN 'admin' THEN 2 WHEN 'staff' THEN 3 ELSE 4 END")
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'phone' => $user->phone,
                'title' => $user->title,
                'department' => $user->department,
                'permissions' => $user->permissions,
                'tasks_count' => $user->tasks_count,
                'completed_tasks_count' => $user->completed_tasks_count,
                'created_at' => $user->created_at->format('M d, Y'),
            ]);

        return Inertia::render('Team/Index', [
            'users' => $users,
            'stats' => [
                'total' => User::count(),
                'owners' => User::where('role', 'owner')->count(),
                'staff' => User::where('role', 'staff')->count(),
            ],
        ]);
    })->name('team.index');

    // Agent Templates
    Route::get('/agents/templates', function () {
        $templates = \App\Models\AgentTemplate::orderBy('usage_count', 'desc')->get();
        $categories = \App\Models\AgentTemplate::distinct()->pluck('category')->filter()->values();

        return Inertia::render('Agents/Templates', [
            'templates' => $templates,
            'categories' => $categories,
        ]);
    })->name('agents.templates');

    // Agents
    Route::get('/agents', function () {
        $agents = Agent::withCount(['runs', 'runs as completed_runs_count' => function ($query) {
            $query->where('status', 'completed');
        }])
            ->get()
            ->map(fn ($agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'slug' => $agent->slug,
                'description' => $agent->description,
                'status' => $agent->status,
                'model' => $agent->model,
                'requires_approval' => $agent->requires_approval,
                'use_consortium' => $agent->use_consortium,
                'max_budget_usd' => $agent->max_budget_usd,
                'runs_count' => $agent->runs_count,
                'completed_runs_count' => $agent->completed_runs_count,
                'total_cost' => $agent->runs()->sum('cost_usd'),
                'tools' => $agent->allowed_tools ?? [],
                'system_prompt' => $agent->system_prompt,
                'skill_file' => $agent->skill_file,
                'schedule' => $agent->schedule,
                'trigger_config' => $agent->trigger_config,
            ]);

        return Inertia::render('Agents/Index', [
            'agents' => $agents,
            'stats' => [
                'total' => Agent::count(),
                'active' => Agent::where('status', 'active')->count(),
                'total_runs' => AgentRun::count(),
                'total_cost' => AgentRun::sum('cost_usd'),
            ],
        ]);
    })->name('agents.index');

    // Cost Tracking
    Route::get('/costs', [App\Http\Controllers\CostTrackingController::class, 'index'])->name('costs.index');
    Route::get('/api/costs/summary', [App\Http\Controllers\CostTrackingController::class, 'summary'])->name('api.costs.summary');

    // Approvals
    Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::get('/approvals/{approval}', [ApprovalController::class, 'show'])->name('approvals.show');

    // Vault
    Route::get('/vault', function () {
        $secrets = VaultSecret::orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($secret) => [
                'id' => $secret->id,
                'name' => $secret->name,
                'key' => $secret->key,
                'category' => $secret->category ?? 'general',
                'description' => $secret->description,
                'is_sensitive' => $secret->is_sensitive,
                'is_active' => $secret->is_active,
                'allowed_agents' => $secret->allowed_agents ?? [],
                'expires_at' => $secret->expires_at?->diffForHumans(),
                'is_expiring_soon' => $secret->expires_at && $secret->expires_at->isBefore(now()->addDays(30)),
                'access_count' => $secret->access_count,
                'created_at' => $secret->created_at->format('M d, Y'),
            ]);

        $categories = VaultSecret::selectRaw('category, count(*) as count')
            ->groupBy('category')
            ->pluck('count', 'category')
            ->toArray();

        return Inertia::render('Vault/Index', [
            'secrets' => $secrets,
            'stats' => [
                'total' => VaultSecret::count(),
                'active' => VaultSecret::where('is_active', true)->count(),
                'expiring_soon' => VaultSecret::whereNotNull('expires_at')
                    ->where('expires_at', '<', now()->addDays(30))
                    ->count(),
                'categories' => $categories,
            ],
        ]);
    })->name('vault.index');

    // Tasks (Global)
    Route::get('/tasks', function () {
        $tasks = Task::with(['project.client', 'assignee', 'externalMappings.source.connection'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'review' THEN 3 WHEN 'completed' THEN 4 END")
            ->orderBy('position')
            ->orderBy('due_date')
            ->get()
            ->map(fn ($task) => [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'status' => $task->status,
                'priority' => $task->priority,
                'position' => $task->position,
                'project' => $task->project ? [
                    'id' => $task->project->id,
                    'name' => $task->project->name,
                    'slug' => $task->project->slug,
                    'client_id' => $task->project->client_id,
                    'client_name' => $task->project->client->name,
                    'client_slug' => $task->project->client->slug,
                ] : null,
                'assignee' => $task->assignee_info,
                'due_date' => $task->due_date?->format('M d'),
                'source' => $task->source,
                'external_url' => $task->externalMappings->first()?->external_url,
                'external_platform' => $task->externalMappings->first()?->source?->connection?->platform,
                'created_at' => $task->created_at->diffForHumans(),
                'metadata' => $task->metadata,
            ]);

        return Inertia::render('Tasks/Index', [
            'tasks' => $tasks,
            'stats' => [
                'total' => Task::count(),
                'pending' => Task::where('status', 'pending')->count(),
                'in_progress' => Task::where('status', 'in_progress')->count(),
                'review' => Task::where('status', 'review')->count(),
                'completed_today' => Task::where('status', 'completed')->whereDate('updated_at', today())->count(),
                'overdue' => Task::where('status', '!=', 'completed')->whereNotNull('due_date')->where('due_date', '<', today())->count(),
            ],
            'team' => User::select('id', 'name')->get(),
            'clients' => Client::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'projects' => Project::with('client:id,name')->whereIn('status', ['active', 'on_hold'])->orderBy('name')->get()->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'client_id' => $p->client_id,
                'client_name' => $p->client?->name,
            ]),
            'agents' => Agent::where('status', 'active')->orderBy('name')->get(['id', 'name', 'slug']),
            'clientContacts' => ClientContact::with('client:id,name')
                ->whereHas('client', fn ($q) => $q->where('status', 'active'))
                ->whereNotIn('email', User::pluck('email'))
                ->orderBy('name')
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'client_id' => $c->client_id,
                    'client_name' => $c->client?->name,
                ]),
        ]);
    })->name('tasks.index');

    // Calendar Event Detail (for notification action URLs)
    Route::get('/calendar/{event}', function (App\Models\CalendarEvent $event) {
        return Inertia::render('Calendar/Show', [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'notes' => $event->notes,
                'start_at' => $event->start_at?->format('M d, Y h:i A'),
                'end_at' => $event->end_at?->format('M d, Y h:i A'),
                'location' => $event->location,
                'meet_link' => $event->meet_link,
                'attendees' => $event->attendees ?? [],
                'is_client_meeting' => $event->is_client_meeting,
                'is_past' => $event->isPast(),
                'client' => $event->client ? [
                    'id' => $event->client->id,
                    'name' => $event->client->name,
                    'slug' => $event->client->slug,
                ] : null,
                'parsed_summary' => $event->parsed_summary,
                'key_decisions' => $event->key_decisions ?? [],
            ],
        ]);
    })->name('calendar.show');

    // Project Detail (scoped by client to handle duplicate slugs across clients)
    Route::get('/clients/{client:slug}/projects/{projectSlug}', function (Client $client, string $projectSlug) {
        $project = Project::with(['client.slackChannel', 'milestones', 'tasks.assignee', 'tasks.externalMappings.source.connection', 'slackChannel'])
            ->where('client_id', $client->id)
            ->where('slug', $projectSlug)
            ->firstOrFail();

        $team = User::whereHas('tasks', fn ($q) => $q->where('project_id', $project->id))
            ->withCount(['tasks' => fn ($q) => $q->where('project_id', $project->id)])
            ->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'tasks_count' => $u->tasks_count]);

        // Get recent project activity from all tasks
        $taskIds = $project->tasks->pluck('id');
        $activities = \App\Models\TaskActivity::whereIn('task_id', $taskIds)
            ->with(['user:id,name', 'agent:id,name', 'task:id,title'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'description' => $a->description,
                'task' => $a->task ? ['id' => $a->task->id, 'title' => $a->task->title] : null,
                'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null,
                'agent' => $a->agent ? ['id' => $a->agent->id, 'name' => $a->agent->name] : null,
                'metadata' => $a->metadata,
                'pr_url' => $a->pr_url,
                'pr_number' => $a->pr_number,
                'hours_logged' => $a->hours_logged,
                'created_at' => $a->created_at->diffForHumans(),
            ]);

        return Inertia::render('Projects/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'description' => $project->description,
                'status' => $project->status,
                'type' => $project->type,
                'budget' => $project->budget,
                'start_date' => $project->start_date?->toDateString(),
                'end_date' => $project->end_date?->toDateString(),
                'timeline_status' => $project->timeline_status,
                'days_elapsed' => $project->days_elapsed,
                'days_remaining' => $project->days_remaining,
                'project_duration_days' => $project->project_duration_days,
                'github_repo' => $project->github_repo,
                'notion_page_id' => $project->notion_page_id,
                'slack_channel_id' => $project->slack_channel_id,
                'slack_channel' => $project->slackChannel ? ['id' => $project->slackChannel->id, 'name' => $project->slackChannel->channel_name ?? $project->slackChannel->name] : null,
                'client' => [
                    'id' => $project->client->id,
                    'name' => $project->client->name,
                    'slug' => $project->client->slug,
                    'slack_channel_id' => $project->client->slack_channel_id,
                    'slack_channel' => $project->client->slackChannel ? ['id' => $project->client->slackChannel->id, 'name' => $project->client->slackChannel->channel_name ?? $project->client->slackChannel->name] : null,
                ],
                'tasks' => $project->tasks->map(fn ($t) => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'description' => $t->description,
                    'milestone_id' => $t->milestone_id,
                    'status' => $t->status,
                    'priority' => $t->priority,
                    'assignee' => $t->assignee_info,
                    'due_date' => $t->due_date?->format('M d'),
                    'due_date_raw' => $t->due_date?->toDateString(),
                    'estimated_hours' => $t->estimated_hours,
                    'position' => $t->position,
                    'source' => $t->source,
                    'external_url' => $t->externalMappings->firstWhere(fn ($m) => filled($m->external_url))?->external_url,
                    'external_platform' => $t->externalMappings->first()?->source?->connection?->platform
                        ?? $t->externalMappings->first()?->source?->type,
                    'metadata' => $t->metadata,
                ]),
                'milestones' => $project->milestones->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'status' => $m->status,
                    'due_date' => $m->due_date?->format('M d, Y'),
                    'tasks_count' => $project->tasks->where('milestone_id', $m->id)->count(),
                    'completed_tasks_count' => $project->tasks->where('milestone_id', $m->id)->where('status', 'completed')->count(),
                ]),
                'created_at' => $project->created_at->format('M d, Y'),
            ],
            'stats' => [
                'total_tasks' => $project->tasks->count(),
                'completed_tasks' => $project->tasks->where('status', 'completed')->count(),
                'in_progress_tasks' => $project->tasks->where('status', 'in_progress')->count(),
                'pending_tasks' => $project->tasks->where('status', 'pending')->count(),
                'completion_pct' => $project->tasks->count() > 0 ? round(($project->tasks->where('status', 'completed')->count() / $project->tasks->count()) * 100) : 0,
                'hours_logged' => $project->total_hours,
                'budget_used' => $project->hourly_rate ? round($project->total_hours * $project->hourly_rate, 2) : 0,
                'budget_remaining' => $project->budget ? max(0, $project->budget - ($project->hourly_rate ? $project->total_hours * $project->hourly_rate : 0)) : 0,
            ],
            'team' => $team,
            'activities' => $activities,
            'slackChannels' => \App\Models\SlackChannel::where('is_archived', false)
                ->orderBy('channel_name')
                ->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->channel_name ?? $c->name]),
            'agents' => Agent::where('status', 'active')->orderBy('name')->get(['id', 'name', 'slug']),
            'clientContacts' => ClientContact::where('client_id', $client->id)
                ->whereNotIn('email', User::pluck('email'))
                ->orderBy('name')
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'client_id' => $c->client_id,
                    'client_name' => $client->name,
                ]),
        ]);
    })->name('projects.show');

    // Client Activity Feed — synthesised current work across Slack/email/GitHub/tasks
    Route::get('/clients/{client:slug}/activity', [\App\Http\Controllers\ActivityController::class, 'show'])
        ->name('clients.activity');

    Route::get('/clients/{client:slug}/projects/{projectSlug}/activity', [\App\Http\Controllers\ActivityController::class, 'showForProject'])
        ->name('projects.activity');

    Route::post('/clients/{client:slug}/activity/refresh', [\App\Http\Controllers\ActivityController::class, 'refresh'])
        ->name('clients.activity.refresh');

    // Redirect old project URLs (by ID or slug) to new client-scoped URL
    Route::get('/projects/{identifier}', function (string $identifier) {
        // Try to find by ID first, then by slug
        $project = is_numeric($identifier)
            ? Project::with('client')->findOrFail($identifier)
            : Project::with('client')->where('slug', $identifier)->firstOrFail();

        return redirect("/clients/{$project->client->slug}/projects/{$project->slug}");
    })->name('projects.show.redirect');

    // Client Detail
    Route::get('/clients/{slug}', function (string $slug) {
        $client = Client::with(['contacts', 'projects', 'slackChannel', 'notes.user'])
            ->where('slug', $slug)
            ->firstOrFail();

        // Get users with portal access
        $portalUsers = \App\Models\User::where('client_id', $client->id)
            ->where('role', 'client')
            ->get(['id', 'name', 'email', 'created_at']);

        // Get pending invitations
        $pendingInvitations = \App\Models\ClientInvitation::where('client_id', $client->id)
            ->pending()
            ->with('invitedBy:id,name')
            ->get(['id', 'email', 'invited_by', 'created_at', 'expires_at']);

        $projects = $client->projects->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'status' => $p->status,
            'type' => $p->type,
            'budget' => $p->budget,
            'tasks_count' => $p->tasks()->count(),
            'completed_tasks_count' => $p->tasks()->where('status', 'completed')->count(),
        ]);

        return Inertia::render('Clients/Show', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'slug' => $client->slug,
                'description' => $client->description,
                'health_score' => $client->health_score,
                'status' => $client->status,
                'website' => $client->website,
                'slack_channel' => $client->slack_channel,
                'slack_channel_id' => $client->slack_channel_id,
                'contacts' => $client->contacts,
                'projects' => $projects,
                'created_at' => $client->created_at->format('M d, Y'),
                // Billing settings
                'billing_email' => $client->billing_email,
                'billing_cc_emails' => $client->billing_cc_emails,
                'payment_terms' => $client->payment_terms,
                'default_hourly_rate' => $client->default_hourly_rate,
                'default_tax_rate' => $client->default_tax_rate,
                'recurring_invoice_enabled' => $client->recurring_invoice_enabled,
                'recurring_invoice_amount' => $client->recurring_invoice_amount,
                'recurring_invoice_day' => $client->recurring_invoice_day,
                'recurring_invoice_auto_send' => $client->recurring_invoice_auto_send,
                'recurring_invoice_description' => $client->recurring_invoice_description,
                'recurring_invoice_project_id' => $client->recurring_invoice_project_id,
            ],
            'reminderSchedule' => (function () use ($client) {
                $override = \App\Models\InvoiceReminderSchedule::query()
                    ->where('scope', \App\Models\InvoiceReminderSchedule::SCOPE_CLIENT)
                    ->where('client_id', $client->id)
                    ->first();

                $global = \App\Models\InvoiceReminderSchedule::resolve();

                return [
                    'has_override' => (bool) $override,
                    'override' => $override ? [
                        'enabled' => $override->enabled,
                        'entries' => \App\Models\InvoiceReminderSchedule::normalize($override->schedule),
                    ] : null,
                    'global' => $global,
                    'limits' => [
                        'min_offset' => \App\Models\InvoiceReminderSchedule::MIN_OFFSET,
                        'max_offset' => \App\Models\InvoiceReminderSchedule::MAX_OFFSET,
                    ],
                ];
            })(),
            'stats' => [
                'total_projects' => $client->projects->count(),
                'active_projects' => $client->projects->where('status', 'active')->count(),
                'total_budget' => $client->projects->sum('budget'),
                'total_tasks' => $client->projects->sum(fn ($p) => $p->tasks()->count()),
                'completed_tasks' => $client->projects->sum(fn ($p) => $p->tasks()->where('status', 'completed')->count()),
            ],
            'recentActivity' => collect([
                // Tasks from client's projects
                ...$client->projects->flatMap(function ($project) {
                    return $project->tasks()
                        ->latest('updated_at')
                        ->limit(5)
                        ->get()
                        ->map(fn ($task) => [
                            'id' => 'task-'.$task->id,
                            'type' => 'task',
                            'description' => match ($task->status) {
                                'completed' => "Task completed: {$task->title}",
                                'in_progress' => "Task started: {$task->title}",
                                default => "Task updated: {$task->title}",
                            },
                            'time' => $task->updated_at,
                            'link' => "/projects/{$project->slug}",
                        ]);
                }),
                // Slack messages from client's channel
                ...(function () use ($client) {
                    if (! $client->slack_channel_id) {
                        return collect([]);
                    }

                    return \App\Models\SlackMessage::where('channel_id', $client->slack_channel_id)
                        ->where('user_is_external', true)
                        ->latest()
                        ->limit(5)
                        ->get()
                        ->map(fn ($msg) => [
                            'id' => 'slack-'.$msg->id,
                            'type' => 'message',
                            'description' => 'Slack: '.\Illuminate\Support\Str::limit($msg->content ?? 'Message from '.($msg->user_name ?? 'client'), 60),
                            'time' => $msg->posted_at ?? $msg->created_at,
                            'link' => null,
                        ]);
                })(),
                // Notes
                ...$client->notes->take(3)->map(fn ($note) => [
                    'id' => 'note-'.$note->id,
                    'type' => 'note',
                    'description' => "Note by {$note->user->name}: ".\Illuminate\Support\Str::limit($note->content, 50),
                    'time' => $note->created_at,
                    'link' => null,
                ]),
            ])
                ->sortByDesc('time')
                ->take(10)
                ->values()
                ->map(fn ($a) => [
                    'id' => $a['id'],
                    'type' => $a['type'],
                    'description' => $a['description'],
                    'time' => $a['time']?->diffForHumans() ?? 'Unknown',
                    'link' => $a['link'] ?? null,
                ]),
            'portalUsers' => $portalUsers,
            'pendingInvitations' => $pendingInvitations,
            'slackChannels' => \App\Models\SlackChannel::where('is_archived', false)
                ->orderBy('channel_name')
                ->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->channel_name ?? $c->name]),
            'notes' => $client->notes->sortByDesc('created_at')->values()->map(fn ($n) => [
                'id' => $n->id,
                'content' => $n->content,
                'user' => [
                    'id' => $n->user->id,
                    'name' => $n->user->name,
                ],
                'created_at' => $n->created_at->format('M j, Y'),
            ]),
        ]);
    })->name('clients.show');

    // Agent Detail
    Route::get('/agents/{slug}', function (string $slug) {
        $agent = Agent::where('slug', $slug)->firstOrFail();
        $runs = $agent->runs()->orderBy('created_at', 'desc')->limit(20)->get();

        $completedRuns = $agent->runs()->where('status', 'completed')->count();
        $failedRuns = $agent->runs()->where('status', 'failed')->count();
        $totalRuns = $agent->runs()->count();

        return Inertia::render('Agents/Show', [
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'slug' => $agent->slug,
                'description' => $agent->description,
                'status' => $agent->status,
                'model' => $agent->model,
                'requires_approval' => $agent->requires_approval,
                'max_budget_usd' => $agent->max_budget_usd,
                'allowed_tools' => $agent->allowed_tools,
                'circuit_broken_at' => $agent->circuit_broken_at,
            ],
            'stats' => [
                'total_runs' => $totalRuns,
                'completed_runs' => $completedRuns,
                'failed_runs' => $failedRuns,
                'success_rate' => $totalRuns > 0 ? round(($completedRuns / $totalRuns) * 100) : 0,
                'total_cost' => (float) ($agent->runs()->sum('cost_usd') ?? 0),
                'avg_cost' => (float) ($agent->runs()->avg('cost_usd') ?? 0),
                'avg_duration_ms' => (float) ($agent->runs()->avg('duration_ms') ?? 0),
                'runs_today' => $agent->runs()->whereDate('created_at', today())->count(),
                'cost_today' => (float) ($agent->runs()->whereDate('created_at', today())->sum('cost_usd') ?? 0),
            ],
            'runs' => $runs->map(fn ($r) => [
                'id' => $r->id,
                'session_id' => $r->session_id,
                'status' => $r->status,
                'task' => $r->task,
                'cost_usd' => $r->cost_usd,
                'duration_ms' => $r->duration_ms,
                'started_at' => $r->started_at?->diffForHumans(),
                'completed_at' => $r->completed_at?->diffForHumans(),
                'has_approval' => $r->approvalRequest()->exists(),
            ]),
            'costHistory' => collect(range(6, 0))->map(fn ($i) => [
                'date' => now()->subDays($i)->format('M d'),
                'cost' => (float) $agent->runs()->whereDate('created_at', now()->subDays($i))->sum('cost_usd'),
            ]),
        ]);
    })->name('agents.show');

    // Agent Run Detail
    Route::get('/agents/{slug}/runs/{runId}', function (string $slug, int $runId) {
        $agent = Agent::where('slug', $slug)->firstOrFail();
        $run = AgentRun::with('approvalRequest')->where('agent_id', $agent->id)->findOrFail($runId);

        return Inertia::render('Agents/Runs/Show', [
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'slug' => $agent->slug,
                'model' => $agent->model,
            ],
            'run' => [
                'id' => $run->id,
                'session_id' => $run->session_id,
                'status' => $run->status,
                'task' => $run->task,
                'context' => $run->context,
                'output' => $run->output,
                'cost_usd' => $run->cost_usd,
                'duration_ms' => $run->duration_ms,
                'input_tokens' => $run->input_tokens,
                'output_tokens' => $run->output_tokens,
                'error_message' => $run->error_message,
                'started_at' => $run->started_at?->format('M d, Y H:i:s'),
                'completed_at' => $run->completed_at?->format('M d, Y H:i:s'),
                'created_at' => $run->created_at->format('M d, Y H:i:s'),
                'approval' => $run->approvalRequest ? [
                    'id' => $run->approvalRequest->id,
                    'action_type' => $run->approvalRequest->action_type,
                    'description' => $run->approvalRequest->description,
                    'risk_level' => $run->approvalRequest->risk_level ?? 'medium',
                    'status' => $run->approvalRequest->status,
                    'payload' => $run->approvalRequest->payload,
                    'decided_at' => $run->approvalRequest->decided_at?->format('M d, Y H:i:s'),
                    'decided_by' => $run->approvalRequest->decidedBy?->name,
                ] : null,
            ],
        ]);
    })->name('agents.runs.show');

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/tokens', [ProfileController::class, 'createToken'])->name('profile.tokens.create');
    Route::delete('/profile/tokens/{token}', [ProfileController::class, 'revokeToken'])->name('profile.tokens.revoke');

    Route::post('/demo-mode/toggle', [\App\Http\Controllers\DemoModeController::class, 'toggle'])->name('demo-mode.toggle');

    // Settings
    Route::get('/settings/integrations', [App\Http\Controllers\SettingsController::class, 'integrations'])->name('settings.integrations');
    Route::get('/api/settings/integrations/status', [App\Http\Controllers\SettingsController::class, 'allIntegrationStatus'])->name('settings.integrations.status');

    // Integration Sync Status API (for real-time sync progress)
    Route::get('/api/integrations/sync-status', [App\Http\Controllers\IntegrationStatusController::class, 'index'])->name('integrations.sync-status');
    Route::get('/api/integrations/sync-status/{service}', [App\Http\Controllers\IntegrationStatusController::class, 'show'])->name('integrations.sync-status.show');
    Route::get('/api/integrations/syncing', [App\Http\Controllers\IntegrationStatusController::class, 'syncing'])->name('integrations.syncing');

    // Projects CRUD
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
    Route::post('/projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
    Route::post('/projects/{project}/unarchive', [ProjectController::class, 'unarchive'])->name('projects.unarchive');
    Route::post('/projects/{project}/status', [ProjectController::class, 'updateStatus'])->name('projects.updateStatus');
    Route::post('/projects/{project}/sync-harvest', [ProjectController::class, 'syncToHarvest'])->name('projects.syncToHarvest');
    Route::post('/projects/{project}/distribute-dates', [ProjectController::class, 'distributeDates'])->name('projects.distributeDates');
    Route::get('/api/projects/{project}/preview-distribution', [ProjectController::class, 'previewDistribution'])->name('api.projects.previewDistribution');
    Route::get('/api/projects/{project}/timeline-data', [ProjectController::class, 'timelineData'])->name('api.projects.timelineData');

    // Task Import API
    Route::post('/api/projects/{project}/tasks/parse-document', [App\Http\Controllers\Api\TaskImportController::class, 'parseDocument'])->name('api.projects.tasks.parseDocument');
    Route::post('/api/projects/{project}/tasks/import', [App\Http\Controllers\Api\TaskImportController::class, 'importTasks'])->name('api.projects.tasks.import');
    Route::post('/api/projects/{project}/tasks/import-csv', [App\Http\Controllers\Api\TaskImportController::class, 'importCsv'])->name('api.projects.tasks.importCsv');
    Route::post('/api/projects/{project}/tasks/excel-sheets', [App\Http\Controllers\Api\TaskImportController::class, 'getExcelSheets'])->name('api.projects.tasks.excelSheets');
    Route::post('/api/projects/{project}/tasks/excel-sheet-headers', [App\Http\Controllers\Api\TaskImportController::class, 'getExcelSheetHeaders'])->name('api.projects.tasks.excelSheetHeaders');
    Route::post('/api/projects/{project}/tasks/import-excel', [App\Http\Controllers\Api\TaskImportController::class, 'importExcel'])->name('api.projects.tasks.importExcel');

    // SOW Import API
    Route::post('/api/sow-import/parse', [App\Http\Controllers\SowImportController::class, 'parse'])->name('api.sow-import.parse');
    Route::get('/api/sow-import/parse/{parseId}/status', [App\Http\Controllers\SowImportController::class, 'parseStatus'])->name('api.sow-import.parse-status');
    Route::post('/api/sow-import/confirm', [App\Http\Controllers\SowImportController::class, 'confirm'])->name('api.sow-import.confirm');

    // Clients CRUD
    Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
    Route::put('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
    Route::post('/clients/{client}/archive', [ClientController::class, 'archive'])->name('clients.archive');
    Route::post('/clients/{client}/unarchive', [ClientController::class, 'unarchive'])->name('clients.unarchive');
    Route::post('/clients/{client}/contacts', [ClientController::class, 'addContact'])->name('clients.contacts.store');
    Route::put('/clients/{client}/contacts/{contact}', [ClientController::class, 'updateContact'])->name('clients.contacts.update');
    Route::delete('/clients/{client}/contacts/{contact}', [ClientController::class, 'removeContact'])->name('clients.contacts.destroy');
    Route::post('/clients/{client}/contacts/{contact}/email', [ClientController::class, 'sendContactEmail'])->name('clients.contacts.email');
    Route::post('/clients/{client}/notes', [ClientController::class, 'storeNote'])->name('clients.notes.store');
    Route::delete('/clients/{client}/notes/{note}', [ClientController::class, 'destroyNote'])->name('clients.notes.destroy');

    // Client Reports
    Route::get('/clients/{client}/reports', [App\Http\Controllers\ClientReportController::class, 'index'])->name('clients.reports.index');
    Route::put('/clients/{client}/reports/settings', [App\Http\Controllers\ClientReportController::class, 'updateSettings'])->name('clients.reports.settings.update');
    Route::post('/clients/{client}/reports/generate', [App\Http\Controllers\ClientReportController::class, 'generate'])->name('clients.reports.generate');
    Route::post('/clients/{client}/reports/generate-last-month', [App\Http\Controllers\ClientReportController::class, 'generateLastMonth'])->name('clients.reports.generate-last-month');
    Route::get('/clients/{client}/reports/{report}', [App\Http\Controllers\ClientReportController::class, 'show'])->name('clients.reports.show');
    Route::get('/clients/{client}/reports/{report}/preview', [App\Http\Controllers\ClientReportController::class, 'preview'])->name('clients.reports.preview');
    Route::get('/clients/{client}/reports/{report}/download', [App\Http\Controllers\ClientReportController::class, 'download'])->name('clients.reports.download');
    Route::get('/clients/{client}/reports/{report}/pdf', [App\Http\Controllers\ClientReportController::class, 'viewPdf'])->name('clients.reports.pdf');
    Route::post('/clients/{client}/reports/{report}/regenerate', [App\Http\Controllers\ClientReportController::class, 'regenerate'])->name('clients.reports.regenerate');
    Route::post('/clients/{client}/reports/{report}/send', [App\Http\Controllers\ClientReportController::class, 'send'])->name('clients.reports.send');

    // Retainers
    Route::prefix('retainers')->name('retainers.')->group(function () {
        Route::get('/', [App\Http\Controllers\RetainerController::class, 'index'])->name('index');
        Route::get('/{retainer}', [App\Http\Controllers\RetainerController::class, 'show'])->name('show');
        Route::get('/{retainer}/current', [App\Http\Controllers\RetainerController::class, 'current'])->name('current');
        Route::post('/{retainer}/snapshot', [App\Http\Controllers\RetainerController::class, 'computeSnapshot'])->name('snapshot');
        Route::delete('/{retainer}', [App\Http\Controllers\RetainerController::class, 'destroy'])->name('destroy');
        Route::get('/{period}/report', [App\Http\Controllers\RetainerReportController::class, 'adminShow'])->name('report.show');
        Route::get('/{period}/report/pdf', [App\Http\Controllers\RetainerReportController::class, 'adminDownload'])->name('report.pdf');
        Route::get('/{period}/report/csv', [App\Http\Controllers\RetainerReportController::class, 'timesheetCsv'])->name('report.csv');
        Route::post('/{period}/report/send', [App\Http\Controllers\RetainerReportController::class, 'send'])->name('report.send');
        Route::post('/{period}/report/refresh', [App\Http\Controllers\RetainerReportController::class, 'refresh'])->name('report.refresh');
        Route::post('/{period}/report/entries/{entry}/adjust', [App\Http\Controllers\RetainerReportController::class, 'adjustEntry'])->name('report.entries.adjust');
        Route::delete('/{period}/report/entries/{entry}', [App\Http\Controllers\RetainerReportController::class, 'deleteEntry'])->name('report.entries.delete');
    });
    Route::delete('/clients/{client}/reports/{report}', [App\Http\Controllers\ClientReportController::class, 'destroy'])->name('clients.reports.destroy');

    // Client Invitations (admin)
    Route::post('/clients/{client}/invite', [App\Http\Controllers\ClientInvitationController::class, 'store'])->name('clients.invite');
    Route::post('/invitations/{invitation}/resend', [App\Http\Controllers\ClientInvitationController::class, 'resend'])->name('invitations.resend');
    Route::delete('/invitations/{invitation}', [App\Http\Controllers\ClientInvitationController::class, 'destroy'])->name('invitations.destroy');

    // Client Portal Impersonation (admin only)
    Route::post('/impersonate/client/{client}', [App\Http\Controllers\ImpersonationController::class, 'start'])->name('impersonate.start');
    Route::post('/impersonate/stop', [App\Http\Controllers\ImpersonationController::class, 'stop'])->name('impersonate.stop');

    // Revoke client portal access
    Route::delete('/users/{user}/revoke-client-access', function (\App\Models\User $user) {
        if ($user->role !== 'client') {
            abort(403, 'This user does not have client access to revoke.');
        }
        $user->update(['client_id' => null, 'role' => 'user']);

        return redirect()->back()->with('success', 'Portal access revoked for '.$user->name);
    })->name('users.revoke-client-access');

    // SEO Dashboard & Analytics
    Route::prefix('seo')->name('seo.')->group(function () {
        Route::get('/dashboard', [App\Http\Controllers\SeoController::class, 'dashboard'])->name('dashboard');
        Route::get('/pages', [App\Http\Controllers\SeoController::class, 'pages'])->name('pages');
        Route::get('/pages/{page}', [App\Http\Controllers\SeoController::class, 'showPage'])->name('pages.show');
        Route::delete('/pages/{page}', [App\Http\Controllers\SeoController::class, 'deletePage'])->name('pages.delete');
        Route::get('/keywords', [App\Http\Controllers\SeoController::class, 'keywords'])->name('keywords');
        Route::get('/performance', [App\Http\Controllers\SeoController::class, 'performance'])->name('performance');
        Route::post('/generate-content', [App\Http\Controllers\SeoController::class, 'generateContent'])->name('generate-content');
    });

    // Tasks CRUD
    Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::get('/api/tasks/{task}', [TaskController::class, 'show'])->name('api.tasks.show');
    Route::put('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
    Route::post('/tasks/{task}/status', [TaskController::class, 'updateStatus'])->name('tasks.updateStatus');
    Route::post('/tasks/{task}/priority', [TaskController::class, 'updatePriority'])->name('tasks.updatePriority');
    Route::post('/tasks/{task}/assign', [TaskController::class, 'assign'])->name('tasks.assign');
    Route::post('/tasks/reorder', [TaskController::class, 'reorder'])->name('tasks.reorder');

    // Task Comments
    Route::post('/tasks/{task}/comments', [TaskController::class, 'storeComment'])->name('tasks.comments.store');
    Route::delete('/tasks/{task}/comments/{comment}', [TaskController::class, 'destroyComment'])->name('tasks.comments.destroy');
    Route::post('/tasks/{task}/comments/{comment}/react', [TaskController::class, 'toggleReaction'])->name('tasks.comments.react');

    // Task Agent Execution
    Route::post('/tasks/{task}/assign-agent', [TaskController::class, 'assignAgent'])->name('tasks.assignAgent');
    Route::post('/tasks/{task}/execute-agent', [TaskController::class, 'executeAgent'])->name('tasks.executeAgent');
    Route::get('/api/tasks/{task}/activities', [TaskController::class, 'getActivities'])->name('api.tasks.activities');
    Route::get('/api/tasks/{task}/agent-status', [TaskController::class, 'getAgentStatus'])->name('api.tasks.agentStatus');

    // Mentions API
    Route::get('/api/mentions/search', [App\Http\Controllers\Api\MentionController::class, 'search'])->name('api.mentions.search');

    // Team CRUD
    Route::post('/team', [TeamController::class, 'store'])->name('team.store');
    Route::post('/team/invite', [TeamController::class, 'store'])->name('team.invite');
    Route::put('/team/{user}', [TeamController::class, 'update'])->name('team.update');
    Route::delete('/team/{user}', [TeamController::class, 'destroy'])->name('team.destroy');

    // User Preferences
    Route::post('/user/preferences', function (\Illuminate\Http\Request $request) {
        $request->validate([
            'key' => 'required|string|max:100',
            'value' => 'required',
        ]);
        auth()->user()->setUiPreference($request->input('key'), $request->input('value'));

        return response()->json(['ok' => true]);
    })->name('user.preferences.update');

    // Agents CRUD
    Route::post('/agents/launch', [AgentController::class, 'launchFromInsight'])->name('agents.launch');
    Route::post('/agents', [AgentController::class, 'store'])->name('agents.store');
    Route::put('/agents/{agent}', [AgentController::class, 'update'])->name('agents.update');
    Route::delete('/agents/{agent}', [AgentController::class, 'destroy'])->name('agents.destroy');
    Route::post('/agents/{agent}/status', [AgentController::class, 'updateStatus'])->name('agents.updateStatus');
    Route::post('/agents/{agent}/trigger', [AgentController::class, 'trigger'])->name('agents.trigger');
    Route::post('/agents/{agent}/clone', [AgentController::class, 'clone'])->name('agents.clone');
    Route::post('/agents/{agent}/dry-run', [AgentController::class, 'dryRun'])->name('agents.dryRun');
    Route::get('/agents/{agent}/runs/{runId}/status', [AgentController::class, 'status'])->name('agents.runs.status');
    Route::post('/agents/{agent}/runs/{run}/link', [AgentController::class, 'linkRun'])->name('agents.runs.link');
    Route::post('/agents/{agent}/runs/{run}/cancel', [AgentController::class, 'cancelRun'])->name('agents.runs.cancel');
    Route::post('/agents/{agent}/webhook/regenerate', [App\Http\Controllers\WebhookController::class, 'regenerateToken'])->name('agents.webhook.regenerate');
    Route::post('/agents/{agent}/webhook/disable', [App\Http\Controllers\WebhookController::class, 'disable'])->name('agents.webhook.disable');
    Route::get('/agents/{agent}/webhook/config', [App\Http\Controllers\WebhookController::class, 'config'])->name('agents.webhook.config');
    Route::put('/agents/{agent}/webhook/allowlist', [App\Http\Controllers\WebhookController::class, 'updateAllowlist'])->name('agents.webhook.allowlist');

    // Agents REST API
    Route::get('/api/agents', [AgentController::class, 'list'])->name('api.agents.list');
    Route::post('/api/agents', [AgentController::class, 'apiStore'])->name('api.agents.store');
    Route::get('/api/agents/{agent}', [AgentController::class, 'apiShow'])->name('api.agents.show');
    Route::put('/api/agents/{agent}', [AgentController::class, 'apiUpdate'])->name('api.agents.update');
    Route::delete('/api/agents/{agent}', [AgentController::class, 'apiDestroy'])->name('api.agents.destroy');
    Route::post('/api/agents/{agent}/run', [AgentController::class, 'trigger'])->name('api.agents.run');
    Route::get('/api/agents/{agent}/runs', [AgentController::class, 'runs'])->name('api.agents.runs');
    Route::post('/api/agents/{agent}/clone', [AgentController::class, 'clone'])->name('api.agents.clone');
    Route::post('/api/agents/{agent}/dry-run', [AgentController::class, 'dryRun'])->name('api.agents.dryRun');

    // Agent Interactions API (for interactive Claude sessions)
    // Restricted to internal users only for security
    Route::middleware([\App\Http\Middleware\EnsureInternalUser::class])->group(function () {
        Route::get('/api/interactions/pending', [App\Http\Controllers\Api\InteractionResponseController::class, 'pending'])->name('api.interactions.pending');
        Route::get('/api/interactions/{interaction}', [App\Http\Controllers\Api\InteractionResponseController::class, 'show'])->name('api.interactions.show');
        Route::post('/api/interactions/{interaction}/respond', [App\Http\Controllers\Api\InteractionResponseController::class, 'respond'])->name('api.interactions.respond');
    });

    // Tools API
    Route::get('/api/tools', [App\Http\Controllers\Api\ToolController::class, 'index'])->name('api.tools.index');
    Route::get('/api/tools/{id}', [App\Http\Controllers\Api\ToolController::class, 'show'])->name('api.tools.show');

    // Skills API (SKILL.md file management)
    Route::get('/api/skills', [App\Http\Controllers\Api\SkillController::class, 'index'])->name('api.skills.index');
    Route::post('/api/skills', [App\Http\Controllers\Api\SkillController::class, 'store'])->name('api.skills.store');
    Route::get('/api/skills/{path}', [App\Http\Controllers\Api\SkillController::class, 'show'])->where('path', '.*')->name('api.skills.show');
    Route::put('/api/skills/{path}', [App\Http\Controllers\Api\SkillController::class, 'update'])->where('path', '.*')->name('api.skills.update');
    Route::delete('/api/skills/{path}', [App\Http\Controllers\Api\SkillController::class, 'destroy'])->where('path', '.*')->name('api.skills.destroy');

    // API for command palette
    Route::post('/api/command-palette/chat', [App\Http\Controllers\CommandPaletteController::class, 'chat'])->name('api.commandPalette.chat');
    Route::get('/api/command-palette/search', [App\Http\Controllers\CommandPaletteController::class, 'search'])->name('api.commandPalette.search');
    Route::get('/api/command-palette/object-search', [App\Http\Controllers\CommandPaletteController::class, 'objectSearch'])->name('api.commandPalette.objectSearch');
    Route::get('/api/command-palette/stats', [App\Http\Controllers\CommandPaletteController::class, 'stats'])->name('api.commandPalette.stats');
    Route::get('/api/command-palette/recent', [App\Http\Controllers\CommandPaletteController::class, 'recentCommands'])->name('api.commandPalette.recent');
    Route::post('/api/command-palette/log', [App\Http\Controllers\CommandPaletteController::class, 'logCommand'])->name('api.commandPalette.log');
    Route::get('/api/command-palette/tools', [App\Http\Controllers\CommandPaletteController::class, 'tools'])->name('api.commandPalette.tools');

    // KPI Dashboard API
    Route::get('/api/kpis', [KpiController::class, 'index'])->name('api.kpis');

    // Agent Templates API
    Route::get('/api/agent-templates', [App\Http\Controllers\AgentTemplateController::class, 'index'])->name('api.agentTemplates.index');
    Route::get('/api/agent-templates/categories', [App\Http\Controllers\AgentTemplateController::class, 'categories'])->name('api.agentTemplates.categories');
    Route::get('/api/agent-templates/featured', [App\Http\Controllers\AgentTemplateController::class, 'featured'])->name('api.agentTemplates.featured');
    Route::get('/api/agent-templates/search', [App\Http\Controllers\AgentTemplateController::class, 'search'])->name('api.agentTemplates.search');
    Route::get('/api/agent-templates/{template}', [App\Http\Controllers\AgentTemplateController::class, 'show'])->name('api.agentTemplates.show');
    Route::post('/api/agent-templates/{template}/preview', [App\Http\Controllers\AgentTemplateController::class, 'preview'])->name('api.agentTemplates.preview');
    Route::post('/api/agent-templates/{template}/create-agent', [App\Http\Controllers\AgentTemplateController::class, 'createAgent'])->name('api.agentTemplates.createAgent');
    Route::post('/api/agent-templates/{template}/duplicate', [App\Http\Controllers\AgentTemplateController::class, 'duplicate'])->name('api.agentTemplates.duplicate');
    Route::post('/api/agent-templates', [App\Http\Controllers\AgentTemplateController::class, 'store'])->name('api.agentTemplates.store');
    Route::post('/api/agent-templates/from-agent/{agent}', [App\Http\Controllers\AgentTemplateController::class, 'createFromAgent'])->name('api.agentTemplates.createFromAgent');
    Route::put('/api/agent-templates/{template}', [App\Http\Controllers\AgentTemplateController::class, 'update'])->name('api.agentTemplates.update');
    Route::delete('/api/agent-templates/{template}', [App\Http\Controllers\AgentTemplateController::class, 'destroy'])->name('api.agentTemplates.destroy');

    // Approvals CRUD
    Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');
    Route::post('/approvals/batch-approve', [ApprovalController::class, 'bulkApprove'])->name('approvals.bulkApprove');
    Route::post('/approvals/batch-reject', [ApprovalController::class, 'bulkReject'])->name('approvals.bulkReject');

    // Health Alerts
    Route::get('/health-alerts', [App\Http\Controllers\HealthAlertController::class, 'index'])->name('health-alerts.index');
    Route::get('/health-alerts/settings', [App\Http\Controllers\HealthAlertController::class, 'escalationSettings'])->name('health-alerts.settings');
    Route::get('/health-alerts/{healthAlert}', [App\Http\Controllers\HealthAlertController::class, 'show'])->name('health-alerts.show');
    Route::post('/health-alerts/{healthAlert}/acknowledge', [App\Http\Controllers\HealthAlertController::class, 'acknowledge'])->name('health-alerts.acknowledge');
    Route::post('/health-alerts/{healthAlert}/resolve', [App\Http\Controllers\HealthAlertController::class, 'resolve'])->name('health-alerts.resolve');
    Route::post('/health-alerts/escalation-targets', [App\Http\Controllers\HealthAlertController::class, 'updateEscalationTargets'])->name('health-alerts.updateTargets');
    Route::get('/api/health-alerts/summary', [App\Http\Controllers\HealthAlertController::class, 'summary'])->name('api.health-alerts.summary');
    Route::get('/api/clients/{client}/health-alerts', [App\Http\Controllers\HealthAlertController::class, 'forClient'])->name('api.clients.health-alerts');

    // Leads CRUD
    Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
    Route::put('/leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');
    Route::post('/leads/{lead}/stage', [LeadController::class, 'updateStage'])->name('leads.updateStage');
    Route::post('/leads/{lead}/close', [LeadController::class, 'closeDeal'])->name('leads.close');
    Route::post('/leads/{lead}/assign', [LeadController::class, 'assign'])->name('leads.assign');
    Route::post('/leads/reorder', [LeadController::class, 'reorder'])->name('leads.reorder');


});


// Public retainer report — signed URL shared with clients alongside their invoice.
Route::get(
    '/r/retainer-reports/{period}',
    [\App\Http\Controllers\RetainerReportController::class, 'publicShow']
)->name('retainer-reports.public.show');
Route::get(
    '/r/retainer-reports/{period}/pdf',
    [\App\Http\Controllers\RetainerReportController::class, 'publicDownload']
)->name('retainer-reports.public.download');
Route::get(
    '/r/retainer-reports/{period}/csv',
    [\App\Http\Controllers\RetainerReportController::class, 'publicCsv']
)->name('retainer-reports.public.csv');

Route::middleware(['auth', \App\Http\Middleware\EnsureInternalUser::class])->group(function () {

    // RFP Pipeline
    Route::get('/rfp', [RfpController::class, 'index'])->name('rfp.index');
    Route::post('/rfp', [RfpController::class, 'store'])->name('rfp.store');
    Route::post('/rfp/upload', [RfpController::class, 'uploadDocument'])->name('rfp.upload');
    Route::get('/rfp/learning', [RfpController::class, 'learning'])->name('rfp.learning');
    Route::post('/rfp/learning', [RfpController::class, 'storeInsight'])->name('rfp.learning.store');
    Route::get('/rfp/sources', [RfpController::class, 'sources'])->name('rfp.sources');
    Route::post('/rfp/sources', [RfpController::class, 'storeSource'])->name('rfp.sources.store');
    Route::put('/rfp/sources/{source}', [RfpController::class, 'updateSource'])->name('rfp.sources.update');
    Route::delete('/rfp/sources/{source}', [RfpController::class, 'destroySource'])->name('rfp.sources.destroy');
    Route::post('/rfp/scan-gmail', [RfpController::class, 'scanGmail'])->name('rfp.scanGmail');
    Route::post('/rfp/evaluate-all', [RfpController::class, 'evaluateAll'])->name('rfp.evaluateAll');
    Route::post('/rfp/retrieve-all-documents', [RfpController::class, 'retrieveAllDocuments'])->name('rfp.retrieveAllDocuments');
    Route::get('/rfp/{rfp}', [RfpController::class, 'show'])->name('rfp.show');
    Route::put('/rfp/{rfp}', [RfpController::class, 'update'])->name('rfp.update');
    Route::put('/rfp/{rfp}/status', [RfpController::class, 'updateStatus'])->name('rfp.updateStatus');
    Route::post('/rfp/{rfp}/evaluate', [RfpController::class, 'evaluate'])->name('rfp.evaluate');
    Route::post('/rfp/{rfp}/retrieve-document', [RfpController::class, 'retrieveDocument'])->name('rfp.retrieveDocument');
    Route::post('/rfp/{rfp}/generate-proposal', [RfpController::class, 'generateProposal'])->name('rfp.generateProposal');
    Route::get('/rfp/{rfp}/proposals/{proposal}/review', [RfpController::class, 'proposalReview'])->name('rfp.proposal.review');
    Route::get('/rfp/{rfp}/proposals/{proposal}/pdf', [RfpController::class, 'previewProposalPdf'])->name('rfp.proposal.pdf.preview');
    Route::get('/rfp/{rfp}/proposals/{proposal}/pdf/download', [RfpController::class, 'downloadProposalPdf'])->name('rfp.proposal.pdf.download');
    Route::get('/rfp/{rfp}/proposals/{proposal}/pdf/corporate', [RfpController::class, 'downloadProposalPdfCorporate'])->name('rfp.proposal.pdf.corporate');
    Route::post('/rfp/{rfp}/proposals/{proposal}/google-doc', [RfpController::class, 'exportToGoogleDoc'])->name('rfp.proposal.googleDoc');
    Route::post('/rfp/{rfp}/proposals/{proposal}/send-email', [RfpController::class, 'sendProposalEmail'])->name('rfp.proposal.sendEmail');
    Route::post('/rfp/{rfp}/outcome', [RfpController::class, 'recordOutcome'])->name('rfp.recordOutcome');
    Route::delete('/rfp/{rfp}', [RfpController::class, 'destroy'])->name('rfp.destroy');

    // ICPs CRUD
    Route::get('/icps', [IdealCustomerProfileController::class, 'index'])->name('icps.index');
    Route::post('/icps', [IdealCustomerProfileController::class, 'store'])->name('icps.store');
    Route::put('/icps/{icp}', [IdealCustomerProfileController::class, 'update'])->name('icps.update');
    Route::delete('/icps/{icp}', [IdealCustomerProfileController::class, 'destroy'])->name('icps.destroy');
    Route::post('/icps/{icp}/toggle', [IdealCustomerProfileController::class, 'toggle'])->name('icps.toggle');
    Route::post('/icps/{icp}/score', [IdealCustomerProfileController::class, 'score'])->name('icps.score');

    // Vault CRUD
    Route::post('/vault', [VaultController::class, 'store'])->name('vault.store');
    Route::put('/vault/{vaultSecret}', [VaultController::class, 'update'])->name('vault.update');
    Route::delete('/vault/{vaultSecret}', [VaultController::class, 'destroy'])->name('vault.destroy');
    Route::post('/vault/{vaultSecret}/rotate', [VaultController::class, 'rotate'])->name('vault.rotate');

    // Ollie Site Builder
    Route::get('/ollie', [App\Http\Controllers\OllieController::class, 'builder'])->name('ollie.builder');
    Route::prefix('api/ollie')->group(function () {
        Route::post('/projects', [App\Http\Controllers\OllieController::class, 'createProject'])->name('ollie.projects.create');
        Route::get('/projects/{projectId}', [App\Http\Controllers\OllieController::class, 'projectStatus'])->name('ollie.projects.status');
        Route::get('/projects/{projectId}/download', [App\Http\Controllers\OllieController::class, 'downloadProject'])->name('ollie.projects.download');
        Route::post('/parse-brief', [App\Http\Controllers\OllieController::class, 'parseBrief'])->name('ollie.parse-brief');
        Route::post('/analyze-site', [App\Http\Controllers\OllieController::class, 'analyzeSite'])->name('ollie.analyze-site');
        Route::post('/analyze-repo', [App\Http\Controllers\OllieController::class, 'analyzeRepo'])->name('ollie.analyze-repo');
        Route::post('/generate-theme', [App\Http\Controllers\OllieController::class, 'generateThemeJson'])->name('ollie.generate-theme');
        Route::get('/patterns', [App\Http\Controllers\OllieController::class, 'patterns'])->name('ollie.patterns');
        Route::post('/compose-page', [App\Http\Controllers\OllieController::class, 'composePage'])->name('ollie.compose-page');
        Route::post('/analyze-page', [App\Http\Controllers\OllieController::class, 'analyzePage'])->name('ollie.analyze-page');
        Route::post('/analyze-pages', [App\Http\Controllers\OllieController::class, 'analyzePages'])->name('ollie.analyze-pages');
        Route::get('/analyze-pages/{batchId}', [App\Http\Controllers\OllieController::class, 'getPageAnalysis'])->name('ollie.analyze-pages.get');
    });

    // Site Builder
    Route::get('/site-builder', [App\Http\Controllers\SiteBuilderController::class, 'index'])->name('site-builder.index');
    Route::get('/site-builder/{project}', [App\Http\Controllers\SiteBuilderController::class, 'show'])->name('site-builder.show');

    // Documentation
    Route::get('/docs', [App\Http\Controllers\DocsController::class, 'index'])->name('docs.index');
    Route::get('/docs/search', [App\Http\Controllers\DocsController::class, 'search'])->name('docs.search');
    Route::get('/docs/{slug}', [App\Http\Controllers\DocsController::class, 'index'])
        ->where('slug', '.*')
        ->name('docs.show');

    // Google Integration
    Route::prefix('auth/google')->group(function () {
        Route::get('/', [GoogleIntegrationController::class, 'redirect'])->name('google.redirect');
        Route::get('/callback', [GoogleIntegrationController::class, 'callback'])->name('google.callback');
    });
    Route::prefix('api/integrations/google')->group(function () {
        Route::get('/status', [GoogleIntegrationController::class, 'status'])->name('google.status');
        Route::post('/disconnect', [GoogleIntegrationController::class, 'disconnect'])->name('google.disconnect');
        Route::post('/sync/emails', [GoogleIntegrationController::class, 'syncEmails'])->name('google.sync.emails');
        Route::post('/sync/calendar', [GoogleIntegrationController::class, 'syncCalendar'])->name('google.sync.calendar');
        Route::post('/discover/documents', [GoogleIntegrationController::class, 'discoverDocuments'])->name('google.discover.documents');
        Route::get('/drive/search', [GoogleIntegrationController::class, 'searchDrive'])->name('google.drive.search');
        Route::get('/meetings/upcoming', [GoogleIntegrationController::class, 'upcomingMeetings'])->name('google.meetings.upcoming');
        Route::post('/watches/renew', [GoogleIntegrationController::class, 'renewWatches'])->name('google.watches.renew');
    });

    // Slack Integration
    Route::prefix('auth/slack')->group(function () {
        Route::get('/', [App\Http\Controllers\SlackIntegrationController::class, 'redirect'])->name('slack.redirect');
        Route::get('/callback', [App\Http\Controllers\SlackIntegrationController::class, 'callback'])->name('slack.callback');
    });
    Route::prefix('api/integrations/slack')->group(function () {
        Route::get('/status', [App\Http\Controllers\SlackIntegrationController::class, 'status'])->name('slack.status');
        Route::delete('/workspaces/{workspace}', [App\Http\Controllers\SlackIntegrationController::class, 'disconnect'])->name('slack.disconnect');
        Route::post('/workspaces/{workspace}/sync-channels', [App\Http\Controllers\SlackIntegrationController::class, 'syncChannels'])->name('slack.syncChannels');
        Route::get('/workspaces/{workspace}/channels', [App\Http\Controllers\SlackIntegrationController::class, 'listChannels'])->name('slack.channels');
        Route::put('/channels/{channel}', [App\Http\Controllers\SlackIntegrationController::class, 'updateChannel'])->name('slack.updateChannel');
        Route::post('/channels/{channel}/sync', [App\Http\Controllers\SlackIntegrationController::class, 'syncMessages'])->name('slack.syncMessages');
        Route::get('/channels/{channel}/messages', [App\Http\Controllers\SlackIntegrationController::class, 'recentMessages'])->name('slack.messages');
    });

    // GitHub Pages
    Route::get('/github', function () {
        $installations = \App\Models\GitHubInstallation::withCount('repos')->get();
        $repos = \App\Models\GitHubRepo::with('installation')
            ->withCount(['issues as open_issues_count' => fn ($q) => $q->where('state', 'open')])
            ->withCount(['pullRequests as open_prs_count' => fn ($q) => $q->where('state', 'open')])
            ->orderBy('full_name')
            ->get();
        $issues = \App\Models\GitHubIssue::with('repo')
            ->where('state', 'open')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
        $prs = \App\Models\GitHubPullRequest::with('repo')
            ->where('state', 'open')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return inertia('GitHub/Index', [
            'installations' => $installations->map(fn ($i) => [
                'id' => $i->id,
                'account_login' => $i->account_login,
                'account_type' => $i->account_type,
                'repos_count' => $i->repos_count,
            ]),
            'repos' => $repos->map(fn ($r) => [
                'id' => $r->id,
                'repo_id' => $r->repo_id,
                'full_name' => $r->full_name,
                'name' => $r->name,
                'owner' => $r->owner,
                'is_private' => $r->is_private,
                'default_branch' => $r->default_branch,
                'monitoring_enabled' => $r->monitoring_enabled,
                'open_issues_count' => $r->open_issues_count,
                'open_prs_count' => $r->open_prs_count,
                'last_synced_at' => $r->issues_synced_at,
            ]),
            'issues' => $issues->map(fn ($i) => [
                'id' => $i->id,
                'issue_number' => $i->issue_number,
                'title' => $i->title,
                'state' => $i->state,
                'labels' => $i->labels ?? [],
                'repo' => ['full_name' => $i->repo?->full_name],
                'author' => $i->author,
                'created_at' => $i->github_created_at,
                'html_url' => $i->html_url,
            ]),
            'pullRequests' => $prs->map(fn ($p) => [
                'id' => $p->id,
                'pr_number' => $p->pr_number,
                'title' => $p->title,
                'state' => $p->state,
                'repo' => ['full_name' => $p->repo?->full_name],
                'author' => $p->author,
                'created_at' => $p->github_created_at,
                'html_url' => $p->html_url,
                'is_draft' => $p->is_draft,
                'mergeable' => $p->mergeable,
            ]),
            'stats' => [
                'total_repos' => $repos->count(),
                'open_issues' => $issues->count(),
                'open_prs' => $prs->count(),
            ],
        ]);
    })->middleware('auth')->name('github.index');

    Route::get('/github/pull-requests', fn () => redirect('/github'))->name('github.prs');
    Route::get('/github/issues', fn () => redirect('/github'))->name('github.issues');

    // GitHub App Integration (for org-level access via GitHub App installation)
    Route::get('/auth/github/callback', [App\Http\Controllers\GitHubIntegrationController::class, 'callback'])->name('github.callback');

    // GitHub User OAuth (for personal repo access)
    Route::prefix('auth/github/user')->group(function () {
        Route::get('/', [App\Http\Controllers\GitHubUserController::class, 'redirect'])->name('github.user.redirect');
        Route::get('/callback', [App\Http\Controllers\GitHubUserController::class, 'callback'])->name('github.user.callback');
    });
    Route::prefix('api/integrations/github/user')->group(function () {
        Route::get('/status', [App\Http\Controllers\GitHubUserController::class, 'status'])->name('github.user.status');
        Route::delete('/disconnect', [App\Http\Controllers\GitHubUserController::class, 'disconnect'])->name('github.user.disconnect');
        Route::get('/repositories', [App\Http\Controllers\GitHubUserController::class, 'repositories'])->name('github.user.repositories');
        Route::get('/repositories/search', [App\Http\Controllers\GitHubUserController::class, 'searchRepositories'])->name('github.user.repositories.search');
    });

    Route::prefix('api/integrations/github')->group(function () {
        Route::get('/status', [App\Http\Controllers\GitHubIntegrationController::class, 'status'])->name('github.status');
        Route::get('/install-url', [App\Http\Controllers\GitHubIntegrationController::class, 'installUrl'])->name('github.installUrl');
        Route::post('/sync', [App\Http\Controllers\GitHubIntegrationController::class, 'syncInstallations'])->name('github.sync');
        Route::post('/auto-link', [App\Http\Controllers\GitHubIntegrationController::class, 'autoLinkRepos'])->name('github.autoLink');
        Route::get('/installations/{installation}/repos', [App\Http\Controllers\GitHubIntegrationController::class, 'listRepos'])->name('github.repos');
        Route::put('/repos/{repo}', [App\Http\Controllers\GitHubIntegrationController::class, 'updateRepo'])->name('github.updateRepo');
        Route::post('/repos/{repo}/sync-issues', [App\Http\Controllers\GitHubIntegrationController::class, 'syncIssues'])->name('github.syncIssues');
        Route::post('/repos/{repo}/sync-prs', [App\Http\Controllers\GitHubIntegrationController::class, 'syncPullRequests'])->name('github.syncPrs');
        Route::get('/pull-requests', [App\Http\Controllers\GitHubIntegrationController::class, 'listOpenPrs'])->name('github.openPrs');
        Route::post('/pull-requests/{pr}/approve', [App\Http\Controllers\GitHubIntegrationController::class, 'approvePr'])->name('github.approvePr');
        Route::post('/pull-requests/{pr}/reject', [App\Http\Controllers\GitHubIntegrationController::class, 'rejectPr'])->name('github.rejectPr');

        // Project-specific repo management
        Route::get('/projects/{project}/repos', [App\Http\Controllers\GitHubIntegrationController::class, 'projectRepos'])->name('github.projectRepos');
        Route::get('/projects/{project}/available-repos', [App\Http\Controllers\GitHubIntegrationController::class, 'availableRepos'])->name('github.availableRepos');
        Route::post('/projects/{project}/repos/{repo}/link', [App\Http\Controllers\GitHubIntegrationController::class, 'linkRepo'])->name('github.linkRepo');
        Route::post('/projects/{project}/repos/link-by-name', [App\Http\Controllers\GitHubIntegrationController::class, 'linkRepoByName'])->name('github.linkRepoByName');
        Route::delete('/projects/{project}/repos/{repo}/link', [App\Http\Controllers\GitHubIntegrationController::class, 'unlinkRepo'])->name('github.unlinkRepo');

        // Effort estimation
        Route::get('/projects/{project}/estimate-effort', [App\Http\Controllers\GitHubIntegrationController::class, 'estimateProjectEffort'])->name('github.estimateEffort');
    });

    // Leads / Pipeline
    Route::get('/leads', function () {
        $leads = Lead::with('assignee')->orderBy('stage')->orderBy('position')->orderBy('created_at', 'desc')->get();

        return Inertia::render('Leads/Index', [
            'leads' => $leads->map(fn ($l) => [
                'id' => $l->id,
                'company_name' => $l->company_name,
                'contact_name' => $l->contact_name,
                'contact_email' => $l->contact_email,
                'website' => $l->website,
                'description' => $l->description,
                'stage' => $l->stage,
                'position' => $l->position,
                'source' => $l->source,
                'deal_value' => $l->deal_value,
                'probability' => $l->probability,
                'expected_close_date' => $l->expected_close_date?->format('M d'),
                'assignee' => $l->assignee ? ['id' => $l->assignee->id, 'name' => $l->assignee->name] : null,
                'last_contacted_at' => $l->last_contacted_at?->diffForHumans(),
                'tags' => $l->tags,
            ]),
            'stats' => [
                'total' => $leads->count(),
                'pipeline_value' => $leads->whereIn('stage', ['new', 'qualified', 'proposal', 'negotiation'])->sum('deal_value'),
                'weighted_value' => $leads->whereIn('stage', ['new', 'qualified', 'proposal', 'negotiation'])->sum(fn ($l) => ($l->deal_value ?? 0) * ($l->probability / 100)),
                'won_this_month' => $leads->where('stage', 'won')->filter(fn ($l) => $l->converted_at?->isCurrentMonth())->count(),
                'won_value_this_month' => $leads->where('stage', 'won')->filter(fn ($l) => $l->converted_at?->isCurrentMonth())->sum('deal_value'),
                'conversion_rate' => $leads->whereIn('stage', ['won', 'lost'])->count() > 0
                    ? round(($leads->where('stage', 'won')->count() / $leads->whereIn('stage', ['won', 'lost'])->count()) * 100)
                    : 0,
            ],
            'team' => User::select('id', 'name')->get(),
        ]);
    })->name('leads.index');

    // Harvest Integration
    Route::prefix('auth/harvest')->group(function () {
        Route::get('/', [App\Http\Controllers\HarvestIntegrationController::class, 'redirect'])->name('harvest.redirect');
        Route::get('/callback', [App\Http\Controllers\HarvestIntegrationController::class, 'callback'])->name('harvest.callback');
    });
    Route::prefix('api/integrations/harvest')->group(function () {
        Route::get('/status', [App\Http\Controllers\HarvestIntegrationController::class, 'status'])->name('harvest.status');
        Route::post('/disconnect', [App\Http\Controllers\HarvestIntegrationController::class, 'disconnect'])->name('harvest.disconnect');

        // Sync
        Route::post('/sync/all', [App\Http\Controllers\HarvestIntegrationController::class, 'syncAll'])->name('harvest.syncAll');
        Route::post('/sync/projects', [App\Http\Controllers\HarvestIntegrationController::class, 'syncProjects'])->name('harvest.syncProjects');
        Route::post('/sync/task-categories', [App\Http\Controllers\HarvestIntegrationController::class, 'syncTaskCategories'])->name('harvest.syncTaskCategories');
        Route::post('/sync/time-entries', [App\Http\Controllers\HarvestIntegrationController::class, 'syncTimeEntries'])->name('harvest.syncTimeEntries');
        Route::post('/sync/invoices', [App\Http\Controllers\HarvestIntegrationController::class, 'syncInvoices'])->name('harvest.syncInvoices');

        // Timer
        Route::get('/timers/running', [App\Http\Controllers\HarvestIntegrationController::class, 'runningTimers'])->name('harvest.runningTimers');
        Route::post('/timers/start', [App\Http\Controllers\HarvestIntegrationController::class, 'startTimer'])->name('harvest.startTimer');
        Route::post('/timers/{harvestTimeEntryId}/stop', [App\Http\Controllers\HarvestIntegrationController::class, 'stopTimer'])->name('harvest.stopTimer');
        Route::post('/timers/{harvestTimeEntryId}/restart', [App\Http\Controllers\HarvestIntegrationController::class, 'restartTimer'])->name('harvest.restartTimer');

        // Project linking
        Route::get('/projects', [App\Http\Controllers\HarvestIntegrationController::class, 'listHarvestProjects'])->name('harvest.projects');
        Route::post('/projects/link', [App\Http\Controllers\HarvestIntegrationController::class, 'linkProject'])->name('harvest.linkProject');

        // Reports
        Route::get('/reports/profitability', [App\Http\Controllers\HarvestIntegrationController::class, 'profitabilityReport'])->name('harvest.profitabilityReport');
        Route::get('/reports/project-time', [App\Http\Controllers\HarvestIntegrationController::class, 'projectTimeReport'])->name('harvest.projectTimeReport');
        Route::get('/reports/team-time', [App\Http\Controllers\HarvestIntegrationController::class, 'teamTimeReport'])->name('harvest.teamTimeReport');
    });

    // Notion Integration
    Route::prefix('auth/notion')->group(function () {
        Route::get('/', [App\Http\Controllers\NotionController::class, 'redirect'])->name('notion.redirect');
        Route::get('/callback', [App\Http\Controllers\NotionController::class, 'callback'])->name('notion.callback');
    });
    Route::prefix('api/integrations/notion')->group(function () {
        Route::delete('/connections/{connection}', [App\Http\Controllers\NotionController::class, 'disconnect'])->name('notion.disconnect');
        Route::post('/connections/{connection}/sync', [App\Http\Controllers\NotionController::class, 'sync'])->name('notion.sync');
        Route::get('/connections/{connection}/pages', [App\Http\Controllers\NotionController::class, 'pages'])->name('notion.pages');
        Route::post('/databases/{database}/sync', [App\Http\Controllers\NotionController::class, 'syncDatabase'])->name('notion.syncDatabase');
    });

    // WordPress Integration
    Route::prefix('api/integrations/wordpress')->group(function () {
        Route::post('/sites', [App\Http\Controllers\WordPressController::class, 'store'])->name('wordpress.store');
        Route::delete('/sites/{site}', [App\Http\Controllers\WordPressController::class, 'disconnect'])->name('wordpress.disconnect');
        Route::post('/sites/{site}/sync', [App\Http\Controllers\WordPressController::class, 'sync'])->name('wordpress.sync');
        Route::post('/sites/{site}/discover-capabilities', [App\Http\Controllers\WordPressController::class, 'discoverCapabilities'])->name('wordpress.discoverCapabilities');
        Route::get('/sites/{site}/posts', [App\Http\Controllers\WordPressController::class, 'posts'])->name('wordpress.posts');
        Route::get('/sites/{site}/suggestions', [App\Http\Controllers\WordPressController::class, 'suggestions'])->name('wordpress.suggestions');
        Route::post('/suggestions/{suggestion}/approve', [App\Http\Controllers\WordPressController::class, 'approveSuggestion'])->name('wordpress.approveSuggestion');
        Route::post('/suggestions/{suggestion}/reject', [App\Http\Controllers\WordPressController::class, 'rejectSuggestion'])->name('wordpress.rejectSuggestion');
        Route::post('/suggestions/{suggestion}/publish', [App\Http\Controllers\WordPressController::class, 'publishSuggestion'])->name('wordpress.publishSuggestion');
    });

    // QuickBooks Integration
    Route::prefix('auth/quickbooks')->group(function () {
        Route::get('/', [App\Http\Controllers\QuickBooksController::class, 'redirect'])->name('quickbooks.redirect');
        Route::get('/callback', [App\Http\Controllers\QuickBooksController::class, 'callback'])->name('quickbooks.callback');
    });
    Route::prefix('api/integrations/quickbooks')->group(function () {
        Route::delete('/connections/{connection}', [App\Http\Controllers\QuickBooksController::class, 'disconnect'])->name('quickbooks.disconnect');
        Route::post('/connections/{connection}/sync', [App\Http\Controllers\QuickBooksController::class, 'sync'])->name('quickbooks.sync');
        Route::post('/connections/{connection}/snapshot', [App\Http\Controllers\QuickBooksController::class, 'snapshot'])->name('quickbooks.snapshot');
        Route::get('/connections/{connection}/reports', [App\Http\Controllers\QuickBooksController::class, 'reports'])->name('quickbooks.reports');
        Route::get('/connections/{connection}/cash-flow', [App\Http\Controllers\QuickBooksController::class, 'cashFlow'])->name('quickbooks.cashFlow');
        Route::post('/connections/{connection}/invoices', [App\Http\Controllers\QuickBooksController::class, 'createInvoice'])->name('quickbooks.createInvoice');
        Route::post('/connections/{connection}/payments', [App\Http\Controllers\QuickBooksController::class, 'recordPayment'])->name('quickbooks.recordPayment');
        Route::post('/connections/{connection}/expenses', [App\Http\Controllers\QuickBooksController::class, 'createExpense'])->name('quickbooks.createExpense');
    });

    // LinkedIn Integration
    Route::prefix('auth/linkedin')->group(function () {
        Route::get('/', [App\Http\Controllers\LinkedInIntegrationController::class, 'redirect'])->name('linkedin.redirect');
        Route::get('/callback', [App\Http\Controllers\LinkedInIntegrationController::class, 'callback'])->name('linkedin.callback');
    });
    Route::prefix('api/integrations/linkedin')->group(function () {
        Route::get('/status', [App\Http\Controllers\LinkedInIntegrationController::class, 'status'])->name('linkedin.status');
        Route::post('/disconnect', [App\Http\Controllers\LinkedInIntegrationController::class, 'disconnect'])->name('linkedin.disconnect');
        Route::post('/post', [App\Http\Controllers\LinkedInIntegrationController::class, 'createPost'])->name('linkedin.post');
        Route::get('/organizations', [App\Http\Controllers\LinkedInIntegrationController::class, 'getOrganizations'])->name('linkedin.organizations');
        Route::post('/organizations/set', [App\Http\Controllers\LinkedInIntegrationController::class, 'setOrganization'])->name('linkedin.setOrganization');
    });

    // X (Twitter) Integration
    Route::prefix('auth/x')->group(function () {
        Route::get('/', [App\Http\Controllers\XIntegrationController::class, 'redirect'])->name('x.redirect');
        Route::get('/callback', [App\Http\Controllers\XIntegrationController::class, 'callback'])->name('x.callback');
    });
    Route::prefix('api/integrations/x')->group(function () {
        Route::get('/status', [App\Http\Controllers\XIntegrationController::class, 'status'])->name('x.status');
        Route::post('/disconnect/{id?}', [App\Http\Controllers\XIntegrationController::class, 'disconnect'])->name('x.disconnect');
        Route::post('/tweet', [App\Http\Controllers\XIntegrationController::class, 'createTweet'])->name('x.tweet');
        Route::post('/thread', [App\Http\Controllers\XIntegrationController::class, 'createThread'])->name('x.thread');
        Route::get('/timeline', [App\Http\Controllers\XIntegrationController::class, 'getTimeline'])->name('x.timeline');
        Route::post('/refresh-stats', [App\Http\Controllers\XIntegrationController::class, 'refreshStats'])->name('x.refreshStats');
    });

    // X Bookmarks Dashboard
    Route::prefix('bookmarks')->middleware('auth')->group(function () {
        Route::get('/', [App\Http\Controllers\XBookmarkController::class, 'index'])->name('bookmarks.index');
        Route::get('/scope-status', [App\Http\Controllers\XBookmarkController::class, 'scopeStatus'])->name('bookmarks.scope-status');
        Route::post('/sync/{credentialId}', [App\Http\Controllers\XBookmarkController::class, 'sync'])->name('bookmarks.sync');
        Route::post('/analyze/{credentialId}', [App\Http\Controllers\XBookmarkController::class, 'analyze'])->name('bookmarks.analyze');
        Route::post('/enrich/{credentialId}', [App\Http\Controllers\XBookmarkController::class, 'enrich'])->name('bookmarks.enrich');
        Route::post('/import/preview', [App\Http\Controllers\XBookmarkController::class, 'previewImport'])->name('bookmarks.import.preview');
        Route::post('/import', [App\Http\Controllers\XBookmarkController::class, 'import'])->name('bookmarks.import');
        Route::get('/{id}', [App\Http\Controllers\XBookmarkController::class, 'show'])->name('bookmarks.show');
        Route::post('/{id}/create-pr', [App\Http\Controllers\XBookmarkController::class, 'createPr'])->name('bookmarks.create-pr');
        Route::post('/{id}/dismiss', [App\Http\Controllers\XBookmarkController::class, 'dismiss'])->name('bookmarks.dismiss');
        Route::post('/bulk', [App\Http\Controllers\XBookmarkController::class, 'bulk'])->name('bookmarks.bulk');
    });

    // ClickUp (PM Tool) API
    Route::prefix('api/integrations/clickup')->group(function () {
        Route::post('/connect', [App\Http\Controllers\Api\PmConnectionController::class, 'connectClickUp'])->name('clickup.connect');
        Route::get('/connections/{connection}/structure', [App\Http\Controllers\Api\PmConnectionController::class, 'getClickUpStructure'])->name('clickup.structure');
        Route::patch('/connections/{connection}', [App\Http\Controllers\Api\PmConnectionController::class, 'updateConnection'])->name('clickup.update');
        Route::delete('/connections/{connection}', [App\Http\Controllers\Api\PmConnectionController::class, 'disconnectClickUp'])->name('clickup.disconnect');
        Route::post('/connections/{connection}/sync', [App\Http\Controllers\Api\PmConnectionController::class, 'syncClickUp'])->name('clickup.sync');
        Route::get('/connections/{connection}/sources', [App\Http\Controllers\Api\PmConnectionController::class, 'getSources'])->name('clickup.sources');
        Route::post('/connections/{connection}/sources', [App\Http\Controllers\Api\PmConnectionController::class, 'createSource'])->name('clickup.sources.create');
        Route::delete('/connections/{connection}/sources/{source}', [App\Http\Controllers\Api\PmConnectionController::class, 'deleteSource'])->name('clickup.sources.delete');
    });

    // SpinupWP (WordPress Hosting) API
    Route::prefix('api/integrations/spinupwp')->group(function () {
        Route::get('/status', [App\Http\Controllers\SpinupWpIntegrationController::class, 'status'])->name('spinupwp.status');
        Route::post('/sync-servers', [App\Http\Controllers\SpinupWpIntegrationController::class, 'syncServers'])->name('spinupwp.sync-servers');
        Route::post('/default-server', [App\Http\Controllers\SpinupWpIntegrationController::class, 'setDefaultServer'])->name('spinupwp.default-server');
        Route::get('/sites', [App\Http\Controllers\SpinupWpIntegrationController::class, 'listSites'])->name('spinupwp.sites');
        Route::post('/servers/{spinupServer}/refresh', [App\Http\Controllers\SpinupWpIntegrationController::class, 'refreshServer'])->name('spinupwp.servers.refresh');
        Route::delete('/servers/{spinupServer}', [App\Http\Controllers\SpinupWpIntegrationController::class, 'deleteServer'])->name('spinupwp.servers.delete');
        Route::post('/sites/{spinupSite}/refresh', [App\Http\Controllers\SpinupWpIntegrationController::class, 'refreshSite'])->name('spinupwp.sites.refresh');
        Route::delete('/sites/{spinupSite}', [App\Http\Controllers\SpinupWpIntegrationController::class, 'deleteSite'])->name('spinupwp.sites.delete');
        Route::post('/sites/{spinupSite}/purge-cache', [App\Http\Controllers\SpinupWpIntegrationController::class, 'purgeCache'])->name('spinupwp.sites.purge-cache');
        Route::post('/sites/{spinupSite}/deploy', [App\Http\Controllers\SpinupWpIntegrationController::class, 'triggerDeploy'])->name('spinupwp.sites.deploy');
    });

    // Notifications API
    Route::prefix('api/notifications')->group(function () {
        Route::get('/', [App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/unread-count', [App\Http\Controllers\NotificationController::class, 'unreadCount'])->name('notifications.unreadCount');
        Route::post('/{notification}/read', [App\Http\Controllers\NotificationController::class, 'markAsRead'])->name('notifications.markAsRead');
        Route::post('/mark-all-read', [App\Http\Controllers\NotificationController::class, 'markAllAsRead'])->name('notifications.markAllAsRead');
        Route::post('/{notification}/dismiss', [App\Http\Controllers\NotificationController::class, 'dismiss'])->name('notifications.dismiss');
        Route::post('/dismiss-all', [App\Http\Controllers\NotificationController::class, 'dismissAll'])->name('notifications.dismissAll');
    });

    // Capability Synthesis API (Meta-Agent)
    Route::prefix('api/capabilities')->group(function () {
        Route::get('/human-required', [App\Http\Controllers\CapabilitySynthesisController::class, 'humanRequired'])->name('capabilities.humanRequired');
        Route::get('/briefing', [App\Http\Controllers\CapabilitySynthesisController::class, 'briefing'])->name('capabilities.briefing');
        Route::get('/summary', [App\Http\Controllers\CapabilitySynthesisController::class, 'capabilities'])->name('capabilities.summary');
        Route::get('/gaps', [App\Http\Controllers\CapabilitySynthesisController::class, 'gaps'])->name('capabilities.gaps');
        Route::get('/focus', [App\Http\Controllers\CapabilitySynthesisController::class, 'focusQuery'])->name('capabilities.focus');
        Route::post('/focus/dismiss', [App\Http\Controllers\CapabilitySynthesisController::class, 'dismissItem'])->name('capabilities.focus.dismiss');
    });

    // Meeting Parser Webhooks
    Route::prefix('webhooks/meetings')->group(function () {
        Route::post('/google-calendar', [App\Http\Controllers\MeetingParserWebhookController::class, 'googleCalendar'])->name('webhooks.meetings.google');
        Route::post('/transcription', [App\Http\Controllers\MeetingParserWebhookController::class, 'transcription'])->name('webhooks.meetings.transcription');
    });
    Route::post('/api/meetings/upload', [App\Http\Controllers\MeetingParserWebhookController::class, 'upload'])->name('meetings.upload');

    // Proactive Insights API
    Route::get('/api/insights', [App\Http\Controllers\InsightsController::class, 'index'])->name('api.insights');

    // Agent Analytics
    Route::get('/agents/analytics', [App\Http\Controllers\AgentAnalyticsController::class, 'index'])->name('agents.analytics');
    Route::get('/agents/{agent}/analytics', [App\Http\Controllers\AgentAnalyticsController::class, 'agent'])->name('agents.analytics.agent');
    Route::get('/api/agents/analytics', [App\Http\Controllers\AgentAnalyticsController::class, 'data'])->name('api.agents.analytics');
    Route::get('/api/agents/{agent}/analytics', [App\Http\Controllers\AgentAnalyticsController::class, 'agentData'])->name('api.agents.analytics.agent');
    Route::get('/api/agents/analytics/export', [App\Http\Controllers\AgentAnalyticsController::class, 'export'])->name('api.agents.analytics.export');

    // Prompt Library
    Route::get('/prompts', [App\Http\Controllers\PromptLibraryController::class, 'index'])->name('prompts.index');
    Route::get('/prompts/{promptTemplate}', [App\Http\Controllers\PromptLibraryController::class, 'show'])->name('prompts.show');
    Route::post('/prompts', [App\Http\Controllers\PromptLibraryController::class, 'store'])->name('prompts.store');
    Route::put('/prompts/{promptTemplate}', [App\Http\Controllers\PromptLibraryController::class, 'update'])->name('prompts.update');
    Route::delete('/prompts/{promptTemplate}', [App\Http\Controllers\PromptLibraryController::class, 'destroy'])->name('prompts.destroy');
    Route::post('/prompts/{promptTemplate}/versions', [App\Http\Controllers\PromptLibraryController::class, 'createVersion'])->name('prompts.versions.store');
    Route::post('/prompts/{promptTemplate}/versions/{version}/activate', [App\Http\Controllers\PromptLibraryController::class, 'activateVersion'])->name('prompts.versions.activate');
    Route::get('/prompts/{promptTemplate}/versions/{version}', [App\Http\Controllers\PromptLibraryController::class, 'getVersionContent'])->name('prompts.versions.show');
    Route::post('/prompts/{promptTemplate}/compare', [App\Http\Controllers\PromptLibraryController::class, 'compareVersions'])->name('prompts.compare');
    Route::post('/prompts/{promptTemplate}/ab-test', [App\Http\Controllers\PromptLibraryController::class, 'setAbTestWeights'])->name('prompts.abTest');
    Route::post('/prompts/{promptTemplate}/preview', [App\Http\Controllers\PromptLibraryController::class, 'preview'])->name('prompts.preview');
    Route::post('/prompts/{promptTemplate}/duplicate', [App\Http\Controllers\PromptLibraryController::class, 'duplicate'])->name('prompts.duplicate');

    // Strategic Goals
    Route::get('/goals', [App\Http\Controllers\StrategicGoalController::class, 'index'])->name('goals.index');
    Route::get('/goals/create', [App\Http\Controllers\StrategicGoalController::class, 'create'])->name('goals.create');
    Route::post('/goals', [App\Http\Controllers\StrategicGoalController::class, 'store'])->name('goals.store');
    Route::get('/goals/{goal}', [App\Http\Controllers\StrategicGoalController::class, 'show'])->name('goals.show');
    Route::put('/goals/{goal}', [App\Http\Controllers\StrategicGoalController::class, 'update'])->name('goals.update');
    Route::delete('/goals/{goal}', [App\Http\Controllers\StrategicGoalController::class, 'destroy'])->name('goals.destroy');
    Route::get('/api/goals/{goal}/progress', [App\Http\Controllers\StrategicGoalController::class, 'progress'])->name('api.goals.progress');
    Route::get('/api/goals/{goal}/levers', [App\Http\Controllers\StrategicGoalController::class, 'levers'])->name('api.goals.levers');
    Route::get('/api/goals/{goal}/forecast', [App\Http\Controllers\StrategicGoalController::class, 'forecast'])->name('api.goals.forecast');
    Route::get('/api/funnel-metrics', [App\Http\Controllers\StrategicGoalController::class, 'funnelMetrics'])->name('api.funnelMetrics');

    // Weekly Plans
    Route::get('/weekly-plans', [App\Http\Controllers\WeeklyPlanController::class, 'index'])->name('weekly-plans.index');
    Route::get('/weekly-plans/{weeklyPlan}', [App\Http\Controllers\WeeklyPlanController::class, 'show'])->name('weekly-plans.show');
    Route::post('/weekly-plans/{weeklyPlan}/approve', [App\Http\Controllers\WeeklyPlanController::class, 'approve'])->name('weekly-plans.approve');
    Route::post('/weekly-plans/{weeklyPlan}/activate', [App\Http\Controllers\WeeklyPlanController::class, 'activate'])->name('weekly-plans.activate');
    Route::post('/weekly-plans/{weeklyPlan}/reject', [App\Http\Controllers\WeeklyPlanController::class, 'reject'])->name('weekly-plans.reject');
    Route::post('/weekly-plans/{weeklyPlan}/request-revision', [App\Http\Controllers\WeeklyPlanController::class, 'requestRevision'])->name('weekly-plans.request-revision');
    Route::patch('/weekly-plans/items/{item}', [App\Http\Controllers\WeeklyPlanController::class, 'updateItem'])->name('weekly-plans.items.update');

    // Prospects
    Route::get('/prospects', [App\Http\Controllers\ProspectController::class, 'index'])->name('prospects.index');
    Route::get('/prospects/{prospect}', [App\Http\Controllers\ProspectController::class, 'show'])->name('prospects.show');
    Route::post('/prospects', [App\Http\Controllers\ProspectController::class, 'store'])->name('prospects.store');
    Route::put('/prospects/{prospect}', [App\Http\Controllers\ProspectController::class, 'update'])->name('prospects.update');
    Route::delete('/prospects/{prospect}', [App\Http\Controllers\ProspectController::class, 'destroy'])->name('prospects.destroy');
    Route::post('/prospects/{prospect}/convert', [App\Http\Controllers\ProspectController::class, 'convertToLead'])->name('prospects.convert');

    // Outreach Campaigns
    Route::get('/campaigns', [App\Http\Controllers\OutreachCampaignController::class, 'index'])->name('campaigns.index');
    Route::get('/campaigns/{campaign}', [App\Http\Controllers\OutreachCampaignController::class, 'show'])->name('campaigns.show');
    Route::post('/campaigns', [App\Http\Controllers\OutreachCampaignController::class, 'store'])->name('campaigns.store');
    Route::put('/campaigns/{campaign}', [App\Http\Controllers\OutreachCampaignController::class, 'update'])->name('campaigns.update');
    Route::post('/campaigns/{campaign}/activate', [App\Http\Controllers\OutreachCampaignController::class, 'activate'])->name('campaigns.activate');
    Route::post('/campaigns/{campaign}/pause', [App\Http\Controllers\OutreachCampaignController::class, 'pause'])->name('campaigns.pause');

    // Campaign Sequences
    Route::post('/campaigns/{campaign}/sequences', [App\Http\Controllers\OutreachSequenceController::class, 'store'])->name('campaigns.sequences.store');
    Route::put('/campaigns/{campaign}/sequences/{sequence}', [App\Http\Controllers\OutreachSequenceController::class, 'update'])->name('campaigns.sequences.update');
    Route::delete('/campaigns/{campaign}/sequences/{sequence}', [App\Http\Controllers\OutreachSequenceController::class, 'destroy'])->name('campaigns.sequences.destroy');
    Route::post('/campaigns/{campaign}/sequences/{sequence}/reorder', [App\Http\Controllers\OutreachSequenceController::class, 'reorder'])->name('campaigns.sequences.reorder');

    // Video Library
    Route::get('/videos', function () {
        $user = auth()->user();

        $videos = \App\Models\Video::where('user_id', $user->id)
            ->with(['project:id,name', 'client:id,name', 'task:id,title'])
            ->orderBy('created_at', 'desc')
            ->get();

        $clients = \App\Models\Client::orderBy('name')
            ->get(['id', 'name']);

        $projects = \App\Models\Project::with('client:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'client_id']);

        $folders = $videos->groupBy('folder_path')->map(fn ($v, $folder) => [
            'path' => $folder ?: 'Unfiled',
            'count' => $v->count(),
        ])->values();

        return Inertia::render('Videos/Index', [
            'videos' => $videos->map(fn ($v) => [
                'id' => $v->id,
                'uuid' => $v->uuid,
                'title' => $v->title,
                'folder_path' => $v->folder_path,
                'duration' => $v->duration,
                'status' => $v->status,
                'view_count' => $v->view_count,
                'share_url' => "/v/{$v->share_token}",
                'thumbnail_url' => $v->thumbnail_path ? "/v/{$v->share_token}/thumbnail" : null,
                'project' => $v->project ? ['id' => $v->project->id, 'name' => $v->project->name] : null,
                'client' => $v->client ? ['id' => $v->client->id, 'name' => $v->client->name] : null,
                'task' => $v->task ? ['id' => $v->task->id, 'title' => $v->task->title] : null,
                'created_at' => $v->created_at->diffForHumans(),
                'formatted_recorded_at' => $v->formatted_recorded_at,
                'relative_time' => $v->relative_time,
                'absolute_time' => $v->absolute_time,
                'transcript_status' => $v->transcript_status,
                'has_transcript' => $v->has_transcript,
                'transcript' => $v->transcript,
                'transcript_segments' => $v->transcript_segments,
                'ai_suggested_title' => $v->ai_suggested_title,
                'ai_action_items' => $v->ai_action_items,
            ]),
            'folders' => $folders,
            'stats' => [
                'total' => $videos->count(),
                'total_views' => $videos->sum('view_count'),
                'total_duration' => $videos->sum('duration'),
            ],
            'clients' => $clients,
            'projects' => $projects,
        ]);
    })->name('videos.index');

    // =========================================================================
    // Contractor Management (Admin)
    // =========================================================================
    Route::get('/contractors', [App\Http\Controllers\ContractorController::class, 'index'])->name('contractors.index');
    Route::get('/contractors/{contractor}', [App\Http\Controllers\ContractorController::class, 'show'])->name('contractors.show');
    Route::post('/contractors', [App\Http\Controllers\ContractorController::class, 'store'])->name('contractors.store');
    Route::put('/contractors/{contractor}', [App\Http\Controllers\ContractorController::class, 'update'])->name('contractors.update');
    Route::delete('/contractors/{contractor}', [App\Http\Controllers\ContractorController::class, 'destroy'])->name('contractors.destroy');
    Route::post('/contractors/{contractor}/invite', [App\Http\Controllers\ContractorController::class, 'invite'])->name('contractors.invite');
    Route::post('/contractors/{contractor}/activate', [App\Http\Controllers\ContractorController::class, 'activate'])->name('contractors.activate');
    Route::post('/contractors/{contractor}/suspend', [App\Http\Controllers\ContractorController::class, 'suspend'])->name('contractors.suspend');
    Route::post('/contractors/{contractor}/w9', [App\Http\Controllers\ContractorController::class, 'uploadW9'])->name('contractors.uploadW9');
    Route::post('/contractors/{contractor}/wise-recipient', [App\Http\Controllers\ContractorController::class, 'setupWiseRecipient'])->name('contractors.setupWiseRecipient');

    // Contractor Invoices (Admin)
    Route::get('/contractor-invoices', [App\Http\Controllers\ContractorInvoiceController::class, 'index'])->name('contractor-invoices.index');
    Route::get('/contractor-invoices/pending', [App\Http\Controllers\ContractorInvoiceController::class, 'pending'])->name('contractor-invoices.pending');
    Route::get('/contractor-invoices/{invoice}', [App\Http\Controllers\ContractorInvoiceController::class, 'show'])->name('contractor-invoices.show');
    Route::post('/contractor-invoices/{invoice}/approve', [App\Http\Controllers\ContractorInvoiceController::class, 'approve'])->name('contractor-invoices.approve');
    Route::post('/contractor-invoices/{invoice}/reject', [App\Http\Controllers\ContractorInvoiceController::class, 'reject'])->name('contractor-invoices.reject');
    Route::post('/contractor-invoices/{invoice}/pay', [App\Http\Controllers\ContractorInvoiceController::class, 'pay'])->name('contractor-invoices.pay');
    Route::post('/contractor-invoices/bulk-approve', [App\Http\Controllers\ContractorInvoiceController::class, 'bulkApprove'])->name('contractor-invoices.bulkApprove');

    // Client Invoices (Admin)
    Route::prefix('invoices')->group(function () {
        Route::get('/', [App\Http\Controllers\InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/create', [App\Http\Controllers\InvoiceController::class, 'create'])->name('invoices.create');
        Route::post('/', [App\Http\Controllers\InvoiceController::class, 'store'])->name('invoices.store');

        // Reminder schedule settings (must come before /{invoice} or 'settings' is treated as an ID)
        Route::get('/settings', [App\Http\Controllers\InvoiceReminderScheduleController::class, 'index'])->name('invoices.settings');
        Route::put('/settings/reminders', [App\Http\Controllers\InvoiceReminderScheduleController::class, 'updateGlobal'])->name('invoices.settings.reminders.update');
        Route::put('/settings/reminders/clients/{client}', [App\Http\Controllers\InvoiceReminderScheduleController::class, 'updateForClient'])->name('invoices.settings.reminders.client.update');
        Route::delete('/settings/reminders/clients/{client}', [App\Http\Controllers\InvoiceReminderScheduleController::class, 'destroyForClient'])->name('invoices.settings.reminders.client.destroy');

        Route::get('/{invoice}', [App\Http\Controllers\InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('/{invoice}/edit', [App\Http\Controllers\InvoiceController::class, 'edit'])->name('invoices.edit');
        Route::put('/{invoice}', [App\Http\Controllers\InvoiceController::class, 'update'])->name('invoices.update');
        Route::get('/{invoice}/pdf', [App\Http\Controllers\InvoiceController::class, 'previewPdf'])->name('invoices.pdf.preview');
        Route::get('/{invoice}/pdf/download', [App\Http\Controllers\InvoiceController::class, 'downloadPdf'])->name('invoices.pdf.download');
        Route::post('/{invoice}/send', [App\Http\Controllers\InvoiceController::class, 'send'])->name('invoices.send');
        Route::post('/{invoice}/regenerate-pdf', [App\Http\Controllers\InvoiceController::class, 'regeneratePdf'])->name('invoices.regeneratePdf');
        Route::post('/{invoice}/payments', [App\Http\Controllers\InvoiceController::class, 'recordPayment'])->name('invoices.payments.store');
        Route::delete('/{invoice}/payments/{payment}', [App\Http\Controllers\InvoiceController::class, 'deletePayment'])->name('invoices.payments.destroy');
        Route::post('/{invoice}/cancel', [App\Http\Controllers\InvoiceController::class, 'cancel'])->name('invoices.cancel');
        Route::post('/{invoice}/duplicate', [App\Http\Controllers\InvoiceController::class, 'duplicate'])->name('invoices.duplicate');
        Route::delete('/{invoice}', [App\Http\Controllers\InvoiceController::class, 'destroy'])->name('invoices.destroy');

        // Per-invoice reminder controls
        Route::post('/{invoice}/reminders', [App\Http\Controllers\InvoiceController::class, 'addReminder'])->name('invoices.reminders.store');
        Route::put('/{invoice}/reminders/{reminder}', [App\Http\Controllers\InvoiceController::class, 'updateReminder'])->name('invoices.reminders.update');
        Route::delete('/{invoice}/reminders/{reminder}', [App\Http\Controllers\InvoiceController::class, 'cancelReminder'])->name('invoices.reminders.cancel');
        Route::put('/{invoice}/reminders-disabled', [App\Http\Controllers\InvoiceController::class, 'toggleRemindersDisabled'])->name('invoices.reminders.toggle');
    });

    // Invoice API endpoints
    Route::prefix('api/invoices')->group(function () {
        Route::get('/unbilled-time', [App\Http\Controllers\InvoiceController::class, 'getUnbilledTime'])->name('api.invoices.unbilled-time');
        Route::get('/clients/{client}/projects', [App\Http\Controllers\InvoiceController::class, 'getClientProjects'])->name('api.invoices.client-projects');
    });

    // Reports
    Route::prefix('reports')->group(function () {
        Route::get('/', [App\Http\Controllers\ReportsController::class, 'index'])->name('reports.index');
        Route::get('/time', [App\Http\Controllers\ReportsController::class, 'time'])->name('reports.time');
        Route::get('/profitability', [App\Http\Controllers\ReportsController::class, 'profitability'])->name('reports.profitability');
        Route::get('/payments', [App\Http\Controllers\ReportsController::class, 'payments'])->name('reports.payments');
        Route::get('/ar-aging', [App\Http\Controllers\ReportsController::class, 'arAging'])->name('reports.ar-aging');
        Route::get('/unbilled-time', [App\Http\Controllers\ReportsController::class, 'unbilledTime'])->name('reports.unbilled-time');
    });

    // Wise Integration
    Route::get('/settings/wise', [App\Http\Controllers\WiseController::class, 'index'])->name('wise.index');
    Route::post('/settings/wise', [App\Http\Controllers\WiseController::class, 'store'])->name('wise.store');
    Route::delete('/settings/wise', [App\Http\Controllers\WiseController::class, 'destroy'])->name('wise.destroy');
    Route::prefix('api/integrations/wise')->group(function () {
        Route::get('/balances', [App\Http\Controllers\WiseController::class, 'balances'])->name('wise.balances');
        Route::get('/recipients', [App\Http\Controllers\WiseController::class, 'recipients'])->name('wise.recipients');
        Route::post('/quote', [App\Http\Controllers\WiseController::class, 'quote'])->name('wise.quote');
        Route::get('/transfers/{transfer}/status', [App\Http\Controllers\WiseController::class, 'transferStatus'])->name('wise.transferStatus');
    });

    // My Contractor Settings (for team members who are contractors)
    // These are integrated into the main app - not a separate portal
    Route::prefix('my')->group(function () {
        // Payment setup (bank details, W9, etc.)
        Route::get('/payment-setup', [App\Http\Controllers\MyContractorController::class, 'paymentSetup'])->name('my.payment-setup');
        Route::post('/payment-setup/personal-info', [App\Http\Controllers\MyContractorController::class, 'updatePersonalInfo'])->name('my.payment-setup.personal-info');
        Route::post('/payment-setup/w9', [App\Http\Controllers\MyContractorController::class, 'submitW9'])->name('my.payment-setup.w9');
        Route::post('/payment-setup/bank-details', [App\Http\Controllers\MyContractorController::class, 'submitBankDetails'])->name('my.payment-setup.bank-details');

        // My invoices (submit invoices for payment)
        Route::get('/invoices', [App\Http\Controllers\MyContractorController::class, 'invoices'])->name('my.invoices');
        Route::get('/invoices/create', [App\Http\Controllers\MyContractorController::class, 'createInvoice'])->name('my.invoices.create');
        Route::post('/invoices', [App\Http\Controllers\MyContractorController::class, 'storeInvoice'])->name('my.invoices.store');
        Route::post('/invoices/{invoice}/submit', [App\Http\Controllers\MyContractorController::class, 'submitInvoice'])->name('my.invoices.submit');

        // My payments (view payment history)
        Route::get('/payments', [App\Http\Controllers\MyContractorController::class, 'payments'])->name('my.payments');
    });

    // System Error Details (for notification click-through)
    Route::get('/system/errors/{notification}', [App\Http\Controllers\SystemErrorController::class, 'show'])->name('system.errors.show');
    Route::post('/system/errors/{notification}/fix', [App\Http\Controllers\SystemErrorController::class, 'triggerFix'])->name('system.errors.fix');

}); // End auth middleware group

// Video API routes (for Chrome extension - uses Sanctum for token auth)
Route::prefix('api/videos')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [App\Http\Controllers\Api\VideoController::class, 'index'])->name('api.videos.index');
    Route::get('/folders', [App\Http\Controllers\Api\VideoController::class, 'folders'])->name('api.videos.folders');
    Route::get('/tasks', [App\Http\Controllers\Api\VideoController::class, 'tasks'])->name('api.videos.tasks');
    Route::post('/upload', [App\Http\Controllers\Api\VideoController::class, 'upload'])->name('api.videos.upload');
    Route::post('/upload-base64', [App\Http\Controllers\Api\VideoController::class, 'uploadBase64'])->name('api.videos.upload.base64');
    Route::post('/upload/init', [App\Http\Controllers\Api\VideoController::class, 'initUpload'])->name('api.videos.upload.init');
    Route::post('/upload/chunk', [App\Http\Controllers\Api\VideoController::class, 'uploadChunk'])->name('api.videos.upload.chunk');
    Route::post('/upload/finalize', [App\Http\Controllers\Api\VideoController::class, 'finalizeUpload'])->name('api.videos.upload.finalize');
    Route::get('/{video}', [App\Http\Controllers\Api\VideoController::class, 'show'])->name('api.videos.show');
    Route::match(['put', 'patch'], '/{video}', [App\Http\Controllers\Api\VideoController::class, 'update'])->name('api.videos.update');
    Route::delete('/{video}', [App\Http\Controllers\Api\VideoController::class, 'destroy'])->name('api.videos.destroy');
    Route::post('/{video}/regenerate-token', [App\Http\Controllers\Api\VideoController::class, 'regenerateToken'])->name('api.videos.regenerateToken');
    Route::post('/{video}/password', [App\Http\Controllers\Api\VideoController::class, 'setPassword'])->name('api.videos.setPassword');
    Route::get('/{video}/analytics', [App\Http\Controllers\Api\VideoController::class, 'analytics'])->name('api.videos.analytics');
    Route::get('/{video}/views', [App\Http\Controllers\Api\VideoController::class, 'views'])->name('api.videos.views');

    // Video Trimming & Versions
    Route::get('/{video}/thumbnails', [App\Http\Controllers\Api\VideoTrimController::class, 'thumbnails'])->name('api.videos.thumbnails');
    Route::post('/{video}/trim', [App\Http\Controllers\Api\VideoTrimController::class, 'trim'])->name('api.videos.trim');
    Route::post('/{video}/trim/preview', [App\Http\Controllers\Api\VideoTrimController::class, 'preview'])->name('api.videos.trim.preview');
    Route::get('/{video}/versions', [App\Http\Controllers\Api\VideoTrimController::class, 'versions'])->name('api.videos.versions');
    Route::post('/{video}/versions/{version}/activate', [App\Http\Controllers\Api\VideoTrimController::class, 'activateVersion'])->name('api.videos.versions.activate');
    Route::delete('/{video}/versions/{version}', [App\Http\Controllers\Api\VideoTrimController::class, 'deleteVersion'])->name('api.videos.versions.delete');
    Route::get('/{video}/versions/{version}/stream', [App\Http\Controllers\Api\VideoTrimController::class, 'streamVersion'])->name('api.videos.versions.stream');

    // Video Comments (authenticated)
    Route::get('/{video}/comments', [App\Http\Controllers\Api\VideoCommentController::class, 'index'])->name('api.videos.comments.index');
    Route::get('/{video}/comments/pending', [App\Http\Controllers\Api\VideoCommentController::class, 'pending'])->name('api.videos.comments.pending');
    Route::get('/{video}/comments/markers', [App\Http\Controllers\Api\VideoCommentController::class, 'markers'])->name('api.videos.comments.markers');
    Route::post('/{video}/comments', [App\Http\Controllers\Api\VideoCommentController::class, 'store'])->name('api.videos.comments.store');
    Route::post('/{video}/comments/{comment}/approve', [App\Http\Controllers\Api\VideoCommentController::class, 'approve'])->name('api.videos.comments.approve');
    Route::delete('/{video}/comments/{comment}', [App\Http\Controllers\Api\VideoCommentController::class, 'destroy'])->name('api.videos.comments.destroy');
});

// Public Video Routes (no auth required for sharing)
Route::prefix('v')->group(function () {
    Route::get('/{shareToken}', [App\Http\Controllers\PublicVideoController::class, 'show'])->name('videos.public.show');
    Route::post('/{shareToken}/verify-password', [App\Http\Controllers\PublicVideoController::class, 'verifyPassword'])->name('videos.public.verifyPassword');
    Route::get('/{shareToken}/stream', [App\Http\Controllers\PublicVideoController::class, 'stream'])->name('videos.public.stream');
    Route::get('/{shareToken}/download', [App\Http\Controllers\PublicVideoController::class, 'download'])->name('videos.public.download');
    Route::get('/{shareToken}/thumbnail', [App\Http\Controllers\PublicVideoController::class, 'thumbnail'])->name('videos.public.thumbnail');
    Route::post('/{shareToken}/view', [App\Http\Controllers\PublicVideoController::class, 'recordView'])->name('videos.public.recordView');
    Route::post('/{shareToken}/ping', [App\Http\Controllers\PublicVideoController::class, 'pingView'])->name('videos.public.ping');
    Route::get('/{shareToken}/embed', [App\Http\Controllers\PublicVideoController::class, 'embed'])->name('videos.public.embed');

    // Public comments (viewer-submitted, require moderation)
    Route::get('/{shareToken}/comments', [App\Http\Controllers\PublicVideoController::class, 'comments'])->name('videos.public.comments');
    Route::get('/{shareToken}/comments/markers', [App\Http\Controllers\PublicVideoController::class, 'commentMarkers'])->name('videos.public.comments.markers');
    Route::post('/{shareToken}/comments', [App\Http\Controllers\PublicVideoController::class, 'storeComment'])->name('videos.public.comments.store');
});

// Client Portal Invitation (public, no auth required)
Route::prefix('portal/invite')->group(function () {
    Route::get('/{token}', [App\Http\Controllers\Auth\AcceptInvitationController::class, 'show'])->name('portal.invite.show');
    Route::post('/{token}/accept', [App\Http\Controllers\Auth\AcceptInvitationController::class, 'accept'])->name('portal.invite.accept');
});

// Client Portal Routes (isolated, client-only access)
Route::middleware(['auth', \App\Http\Middleware\ClientPortalMiddleware::class])
    ->prefix('portal')
    ->group(function () {
        Route::get('/', [App\Http\Controllers\ClientPortalController::class, 'dashboard'])->name('portal.dashboard');
        Route::get('/projects', [App\Http\Controllers\ClientPortalController::class, 'projects'])->name('portal.projects');
        Route::get('/projects/{project}', [App\Http\Controllers\ClientPortalController::class, 'projectShow'])->name('portal.projects.show');
        Route::get('/invoices', [App\Http\Controllers\ClientPortalController::class, 'invoices'])->name('portal.invoices');
        Route::get('/statement', [App\Http\Controllers\ClientPortalController::class, 'statement'])->name('portal.statement');

        // Portal task interactions
        Route::get('/api/tasks/{task}', [App\Http\Controllers\PortalTaskController::class, 'show'])->name('portal.tasks.show');
        Route::post('/api/tasks/{task}/comments', [App\Http\Controllers\PortalTaskController::class, 'storeComment'])->name('portal.tasks.comments.store');
        Route::post('/api/tasks/{task}/assign', [App\Http\Controllers\PortalTaskController::class, 'assign'])->name('portal.tasks.assign');
        Route::get('/api/mentions/search', [App\Http\Controllers\PortalTaskController::class, 'mentionSearch'])->name('portal.mentions.search');
    });

// Site Builder API Routes
Route::prefix('api/site-builder')->middleware(['auth'])->group(function () {
    Route::get('/projects', [App\Http\Controllers\SiteBuilderController::class, 'listProjects']);
    Route::post('/projects', [App\Http\Controllers\SiteBuilderController::class, 'createProject']);
    Route::get('/projects/{project}', [App\Http\Controllers\SiteBuilderController::class, 'getProject']);
    Route::get('/projects/{project}/status', [App\Http\Controllers\SiteBuilderController::class, 'getProjectStatus']);
    Route::post('/projects/{project}/deploy', [App\Http\Controllers\SiteBuilderController::class, 'deployProject']);
    Route::get('/projects/{project}/preview', [App\Http\Controllers\SiteBuilderController::class, 'getPreviewUrl']);
    Route::post('/projects/{project}/chat', [App\Http\Controllers\SiteBuilderController::class, 'sendChatMessage']);
});

// Internal API for agents (no user auth, uses X-Agent-Token header)
Route::post('/api/internal/website-builder/progress', [App\Http\Controllers\WebsiteBuilderController::class, 'agentUpdateProgress'])
    ->name('api.internal.website-builder.progress');
Route::post('/api/internal/website-builder/deploy', [App\Http\Controllers\WebsiteBuilderController::class, 'agentDeploy'])
    ->name('api.internal.website-builder.deploy');

// Unified Website Builder (replaces both Ollie and SiteBuilder)
Route::middleware(['auth'])->group(function () {
    Route::get('/website-builder', [App\Http\Controllers\WebsiteBuilderController::class, 'index'])->name('website-builder.index');
    Route::get('/website-builder/{project}', [App\Http\Controllers\WebsiteBuilderController::class, 'show'])->name('website-builder.show');

    Route::prefix('api/website-builder')->name('api.website-builder.')->group(function () {
        Route::get('/projects', [App\Http\Controllers\WebsiteBuilderController::class, 'listProjects'])->name('projects.list');
        Route::post('/projects', [App\Http\Controllers\WebsiteBuilderController::class, 'createProject'])->name('projects.create');
        Route::put('/projects/{project}', [App\Http\Controllers\WebsiteBuilderController::class, 'updateProject'])->name('projects.update');
        Route::delete('/projects/{project}', [App\Http\Controllers\WebsiteBuilderController::class, 'deleteProject'])->name('projects.delete');
        Route::post('/projects/{project}/restart', [App\Http\Controllers\WebsiteBuilderController::class, 'restartProject'])->name('projects.restart');
        Route::get('/projects/{project}/status', [App\Http\Controllers\WebsiteBuilderController::class, 'getStatus'])->name('projects.status');
        Route::get('/projects/{project}/messages', [App\Http\Controllers\WebsiteBuilderController::class, 'getMessages'])->name('projects.messages');
        Route::post('/projects/{project}/chat', [App\Http\Controllers\WebsiteBuilderController::class, 'chat'])->name('projects.chat');

        Route::post('/projects/{project}/assets', [App\Http\Controllers\WebsiteBuilderController::class, 'uploadAssets'])->name('projects.assets.upload');
        Route::get('/projects/{project}/assets', [App\Http\Controllers\WebsiteBuilderController::class, 'listAssets'])->name('projects.assets.list');
        Route::put('/projects/{project}/assets/{asset}', [App\Http\Controllers\WebsiteBuilderController::class, 'updateAsset'])->name('projects.assets.update');
        Route::delete('/projects/{project}/assets/{asset}', [App\Http\Controllers\WebsiteBuilderController::class, 'deleteAsset'])->name('projects.assets.delete');

        Route::post('/parse-brief', [App\Http\Controllers\WebsiteBuilderController::class, 'parseBrief'])->name('parse-brief');
        Route::post('/analyze-site', [App\Http\Controllers\WebsiteBuilderController::class, 'analyzeSite'])->name('analyze-site');
        Route::post('/analyze-repo', [App\Http\Controllers\WebsiteBuilderController::class, 'analyzeRepo'])->name('analyze-repo');
        Route::post('/analyze-pages', [App\Http\Controllers\WebsiteBuilderController::class, 'analyzePages'])->name('analyze-pages');
        Route::get('/analyze-pages/{batchId}', [App\Http\Controllers\WebsiteBuilderController::class, 'getAnalysisResults'])->name('analyze-pages.results');

        Route::post('/generate-theme', [App\Http\Controllers\WebsiteBuilderController::class, 'generateTheme'])->name('generate-theme');
        Route::post('/compose-page', [App\Http\Controllers\WebsiteBuilderController::class, 'composePage'])->name('compose-page');
        Route::get('/patterns', [App\Http\Controllers\WebsiteBuilderController::class, 'listPatterns'])->name('patterns');
    });
});
