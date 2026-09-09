<?php

namespace App\Http\Controllers;

use App\Jobs\SyncHarvestJob;
use App\Models\HarvestProject;
use App\Services\Harvest\HarvestApiService;
use App\Services\Harvest\HarvestOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HarvestIntegrationController extends Controller
{
    public function __construct(
        protected HarvestOAuthService $oauth,
        protected HarvestApiService $api
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        session(['harvest_oauth_state' => $state]);

        return redirect($this->oauth->getAuthorizationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->state !== session('harvest_oauth_state')) {
            return redirect('/settings/integrations')->with('error', 'Invalid OAuth state');
        }

        try {
            $tokens = $this->oauth->exchangeCodeForTokens($request->code);
            $accounts = $this->oauth->getAccounts($tokens['access_token']);
            $account = $accounts[0] ?? [];

            $credential = $this->oauth->storeCredentials($request->user(), $tokens, $account);

            // Dispatch initial sync
            SyncHarvestJob::dispatch($credential->id)->onQueue('sync');

            return redirect('/settings/integrations')->with('success', 'Harvest connected! Syncing your projects and time entries now...');
        } catch (\Exception $e) {
            return redirect('/settings/integrations')->with('error', 'Failed to connect Harvest: '.$e->getMessage());
        }
    }

    public function disconnect(Request $request): JsonResponse
    {
        $request->user()->harvestCredential?->delete();

        return response()->json(['message' => 'Harvest disconnected']);
    }

    public function status(Request $request): JsonResponse
    {
        $credential = $request->user()->harvestCredential;

        return response()->json([
            'connected' => $credential !== null,
            'account_name' => $credential?->account_name,
            'expires_at' => $credential?->expires_at,
        ]);
    }

    // Sync actions
    public function syncProjects(Request $request): JsonResponse
    {
        $count = $this->api->syncProjects($request->user());

        return response()->json(['synced' => $count]);
    }

    public function syncTaskCategories(Request $request): JsonResponse
    {
        $count = $this->api->syncTaskCategories($request->user());

        return response()->json(['synced' => $count]);
    }

    public function syncTimeEntries(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $count = $this->api->syncTimeEntries(
            $request->user(),
            $request->input('from'),
            $request->input('to')
        );

        return response()->json(['synced' => $count]);
    }

    public function syncInvoices(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $count = $this->api->syncInvoices(
            $request->user(),
            $request->input('from'),
            $request->input('to')
        );

        return response()->json(['synced' => $count]);
    }

    public function syncAll(Request $request): JsonResponse
    {
        $results = [
            'projects' => $this->api->syncProjects($request->user()),
            'task_categories' => $this->api->syncTaskCategories($request->user()),
            'time_entries' => $this->api->syncTimeEntries($request->user()),
            'invoices' => $this->api->syncInvoices($request->user()),
        ];

        return response()->json(['synced' => $results]);
    }

    // Timer actions
    public function startTimer(Request $request): JsonResponse
    {
        $request->validate([
            'harvest_project_id' => 'required|integer',
            'harvest_task_id' => 'required|integer',
            'notes' => 'nullable|string',
        ]);

        $entry = $this->api->createTimeEntry($request->user(), [
            'project_id' => $request->harvest_project_id,
            'task_id' => $request->harvest_task_id,
            'notes' => $request->notes,
            'spent_date' => now()->toDateString(),
        ]);

        return response()->json($entry);
    }

    public function stopTimer(Request $request, int $harvestTimeEntryId): JsonResponse
    {
        $entry = $this->api->stopTimer($request->user(), $harvestTimeEntryId);

        return response()->json($entry);
    }

    public function restartTimer(Request $request, int $harvestTimeEntryId): JsonResponse
    {
        $entry = $this->api->restartTimer($request->user(), $harvestTimeEntryId);

        return response()->json($entry);
    }

    public function runningTimers(Request $request): JsonResponse
    {
        $timers = $this->api->getRunningTimers($request->user());

        return response()->json($timers);
    }

    // Project linking
    public function listHarvestProjects(): JsonResponse
    {
        $projects = HarvestProject::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'harvest_id', 'name', 'code', 'project_id', 'client_id']);

        return response()->json($projects);
    }

    public function linkProject(Request $request): JsonResponse
    {
        $request->validate([
            'harvest_project_id' => 'required|integer',
            'project_id' => 'required|exists:projects,id',
            'client_id' => 'nullable|exists:clients,id',
        ]);

        $harvestProject = $this->api->linkProject(
            $request->harvest_project_id,
            $request->project_id,
            $request->client_id
        );

        return response()->json($harvestProject);
    }

    // Reports
    public function profitabilityReport(Request $request): JsonResponse
    {
        $request->validate([
            'period_type' => 'required|in:daily,weekly,monthly',
            'from' => 'required|date',
            'to' => 'required|date',
            'client_id' => 'nullable|exists:clients,id',
            'project_id' => 'nullable|exists:projects,id',
        ]);

        $snapshot = $this->api->calculateProfitability(
            $request->period_type,
            $request->from,
            $request->to,
            $request->client_id,
            $request->project_id
        );

        return response()->json($snapshot);
    }

    public function projectTimeReport(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $report = $this->api->getProjectTimeReport(
            $request->user(),
            $request->from,
            $request->to
        );

        return response()->json($report);
    }

    public function teamTimeReport(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $report = $this->api->getTeamTimeReport(
            $request->user(),
            $request->from,
            $request->to
        );

        return response()->json($report);
    }
}
