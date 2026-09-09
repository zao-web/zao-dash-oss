<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\EscalationTarget;
use App\Models\HealthAlert;
use App\Services\HealthAlertEscalationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HealthAlertController extends Controller
{
    public function __construct(
        protected HealthAlertEscalationService $escalationService
    ) {}

    /**
     * Dashboard view of all health alerts.
     */
    public function index(Request $request)
    {
        $query = HealthAlert::with(['client:id,name,slug', 'acknowledgedBy:id,name', 'resolvedBy:id,name']);

        // Filter by status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        } else {
            // Default to unresolved
            $query->unresolved();
        }

        // Filter by severity
        if ($severity = $request->input('severity')) {
            $query->where('severity', $severity);
        }

        // Filter by client
        if ($clientId = $request->input('client_id')) {
            $query->where('client_id', $clientId);
        }

        $alerts = $query
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 END")
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return Inertia::render('HealthAlerts/Index', [
            'alerts' => $alerts->through(fn ($alert) => [
                'id' => $alert->id,
                'client' => $alert->client ? [
                    'id' => $alert->client->id,
                    'name' => $alert->client->name,
                    'slug' => $alert->client->slug,
                ] : null,
                'alert_type' => $alert->alert_type,
                'severity' => $alert->severity,
                'severity_color' => $alert->severity_color,
                'health_score' => $alert->health_score,
                'previous_score' => $alert->previous_score,
                'description' => $alert->description,
                'status' => $alert->status,
                'escalation_level' => $alert->escalation_level,
                'escalation_level_name' => $alert->escalation_level_name,
                'acknowledged_by' => $alert->acknowledgedBy?->name,
                'acknowledged_at' => $alert->acknowledged_at?->diffForHumans(),
                'resolved_by' => $alert->resolvedBy?->name,
                'resolved_at' => $alert->resolved_at?->diffForHumans(),
                'next_escalation_at' => $alert->next_escalation_at?->diffForHumans(),
                'created_at' => $alert->created_at->diffForHumans(),
            ]),
            'summary' => $this->escalationService->getDashboardSummary(),
            'filters' => [
                'status' => $request->input('status'),
                'severity' => $request->input('severity'),
                'client_id' => $request->input('client_id'),
            ],
            'clients' => Client::select('id', 'name', 'slug')
                ->whereHas('healthAlerts')
                ->get(),
        ]);
    }

    /**
     * Show a single alert.
     */
    public function show(HealthAlert $healthAlert)
    {
        $healthAlert->load([
            'client:id,name,slug,health_score',
            'acknowledgedBy:id,name',
            'resolvedBy:id,name',
        ]);

        return Inertia::render('HealthAlerts/Show', [
            'alert' => [
                'id' => $healthAlert->id,
                'client' => $healthAlert->client,
                'alert_type' => $healthAlert->alert_type,
                'severity' => $healthAlert->severity,
                'severity_color' => $healthAlert->severity_color,
                'health_score' => $healthAlert->health_score,
                'previous_score' => $healthAlert->previous_score,
                'description' => $healthAlert->description,
                'factors' => $healthAlert->factors,
                'status' => $healthAlert->status,
                'escalation_level' => $healthAlert->escalation_level,
                'escalation_level_name' => $healthAlert->escalation_level_name,
                'escalation_history' => $healthAlert->escalation_history,
                'acknowledged_by' => $healthAlert->acknowledgedBy?->name,
                'acknowledged_at' => $healthAlert->acknowledged_at?->format('M d, Y H:i'),
                'resolved_by' => $healthAlert->resolvedBy?->name,
                'resolved_at' => $healthAlert->resolved_at?->format('M d, Y H:i'),
                'resolution_note' => $healthAlert->resolution_note,
                'next_escalation_at' => $healthAlert->next_escalation_at?->format('M d, Y H:i'),
                'created_at' => $healthAlert->created_at->format('M d, Y H:i'),
            ],
            'related_alerts' => HealthAlert::where('client_id', $healthAlert->client_id)
                ->where('id', '!=', $healthAlert->id)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'severity' => $a->severity,
                    'status' => $a->status,
                    'health_score' => $a->health_score,
                    'created_at' => $a->created_at->diffForHumans(),
                ]),
        ]);
    }

    /**
     * Acknowledge an alert.
     */
    public function acknowledge(HealthAlert $healthAlert)
    {
        if ($healthAlert->status === HealthAlert::STATUS_RESOLVED) {
            return back()->with('error', 'This alert has already been resolved.');
        }

        $this->escalationService->acknowledgeAlert($healthAlert, auth()->user());

        return back()->with('success', 'Alert acknowledged.');
    }

    /**
     * Resolve an alert.
     */
    public function resolve(Request $request, HealthAlert $healthAlert)
    {
        if ($healthAlert->status === HealthAlert::STATUS_RESOLVED) {
            return back()->with('error', 'This alert has already been resolved.');
        }

        $validated = $request->validate([
            'note' => 'nullable|string|max:1000',
        ]);

        $this->escalationService->resolveAlert(
            $healthAlert,
            auth()->user(),
            $validated['note'] ?? null
        );

        return back()->with('success', 'Alert resolved.');
    }

    /**
     * API endpoint for dashboard summary.
     */
    public function summary()
    {
        return response()->json($this->escalationService->getDashboardSummary());
    }

    /**
     * Get alerts for a specific client.
     */
    public function forClient(Client $client)
    {
        $alerts = HealthAlert::forClient($client->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'severity' => $a->severity,
                'severity_color' => $a->severity_color,
                'status' => $a->status,
                'description' => $a->description,
                'health_score' => $a->health_score,
                'escalation_level_name' => $a->escalation_level_name,
                'created_at' => $a->created_at->diffForHumans(),
            ]);

        return response()->json([
            'alerts' => $alerts,
            'unresolved_count' => HealthAlert::forClient($client->id)->unresolved()->count(),
        ]);
    }

    /**
     * Escalation settings management.
     */
    public function escalationSettings()
    {
        $targets = EscalationTarget::with('user:id,name,email')
            ->orderBy('level')
            ->orderBy('order')
            ->get()
            ->groupBy('level')
            ->map(fn ($group) => $group->map(fn ($t) => [
                'id' => $t->id,
                'user' => $t->user,
                'is_active' => $t->is_active,
                'order' => $t->order,
            ]));

        return Inertia::render('HealthAlerts/EscalationSettings', [
            'levels' => [
                ['level' => 0, 'name' => 'Account Manager', 'targets' => $targets->get(0, collect())],
                ['level' => 1, 'name' => 'Manager', 'targets' => $targets->get(1, collect())],
                ['level' => 2, 'name' => 'Director', 'targets' => $targets->get(2, collect())],
                ['level' => 3, 'name' => 'Executive', 'targets' => $targets->get(3, collect())],
            ],
            'users' => \App\Models\User::select('id', 'name', 'email')->get(),
        ]);
    }

    /**
     * Update escalation targets.
     */
    public function updateEscalationTargets(Request $request)
    {
        $validated = $request->validate([
            'level' => 'required|integer|min:0|max:3',
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        // Remove existing targets for this level
        EscalationTarget::where('level', $validated['level'])->delete();

        // Add new targets
        foreach ($validated['user_ids'] as $index => $userId) {
            EscalationTarget::create([
                'level' => $validated['level'],
                'user_id' => $userId,
                'order' => $index,
                'is_active' => true,
            ]);
        }

        return back()->with('success', 'Escalation targets updated.');
    }
}
