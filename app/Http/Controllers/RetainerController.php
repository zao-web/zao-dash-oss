<?php

namespace App\Http\Controllers;

use App\Http\Resources\RetainerResource;
use App\Models\AgentRun;
use App\Models\CalendarEvent;
use App\Models\ClientReport;
use App\Models\RetainerPeriod;
use App\Models\Task;
use App\Services\Reports\RetainerHealthService;
use Inertia\Inertia;

class RetainerController extends Controller
{
    public function index(): \Inertia\Response
    {
        $retainers = RetainerPeriod::withHealthSummary()->get();

        $portfolioMetrics = [
            'total_mrr' => $retainers->sum('monthly_amount'),
            'avg_margin_percent' => $retainers->whereNotNull('effective_margin_percent')
                ->avg('effective_margin_percent'),
            'active_count' => $retainers->count(),
            'critical_count' => $retainers->where('health_status', 'critical')->count(),
            'warning_count' => $retainers->where('health_status', 'warning')->count(),
            'total_human_hours' => $retainers->sum('hours_used'),
            'total_agent_cost' => $retainers->sum('agent_cost_usd'),
            'total_agent_tasks' => $retainers->sum('agent_tasks_completed'),
        ];

        return Inertia::render('Retainers/Index', [
            'retainers' => RetainerResource::collection($retainers),
            'portfolioMetrics' => $portfolioMetrics,
            'currentMonth' => now()->format('F Y'),
        ]);
    }

    public function show(RetainerPeriod $retainer): \Inertia\Response
    {
        $retainer->load('client.contacts');

        $service = app(RetainerHealthService::class);

        // Current snapshot (fresh compute, no persist to avoid mid-view writes)
        $currentSnapshot = $service->computeAndPersistSnapshot(
            $retainer,
            $retainer->period_start,
            $retainer->period_end,
            persist: false,
        );

        // 6-month trend — lite mode skips GitHub API calls + email/Slack
        // aggregation that would compound across 6 periods and time out the
        // request. We only need the hours/margin/health columns here.
        $trend = collect(range(5, 0))->map(function ($monthsAgo) use ($retainer, $service) {
            $start = now()->subMonths($monthsAgo)->startOfMonth();
            $end = now()->subMonths($monthsAgo)->endOfMonth();

            return array_merge(
                $service->computeAndPersistSnapshot($retainer, $start, $end, persist: false, lite: true),
                ['month' => $start->format('M Y')]
            );
        });

        return Inertia::render('Retainers/Show', [
            'retainer' => new RetainerResource($retainer),
            'snapshot' => $currentSnapshot,
            'trend' => $trend,
            'agentRuns' => AgentRun::where('client_id', $retainer->client_id)
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn ($run) => [
                    'id' => $run->id,
                    'task' => \Illuminate\Support\Str::limit($run->task, 80),
                    'effort_type' => $run->effort_type,
                    'cost_usd' => $run->cost_usd,
                    'duration_ms' => $run->duration_ms,
                    'status' => $run->status,
                    'started_at' => $run->started_at?->toIso8601String(),
                ]),
            'meetings' => CalendarEvent::where('client_id', $retainer->client_id)
                ->where('is_client_meeting', true)
                ->where('start_at', '>=', $retainer->period_start)
                ->orderByDesc('start_at')
                ->get()
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'title' => $e->title,
                    'start_at' => $e->start_at->toIso8601String(),
                    'duration_hours' => $e->duration_hours,
                    'attendees' => $e->attendees,
                ]),
            'openTasks' => Task::whereIn('project_id', $retainer->client->projects->pluck('id'))
                ->whereNotIn('status', ['done', 'completed'])
                ->orderBy('priority')
                ->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'priority' => $t->priority,
                    'status' => $t->status,
                    'created_at' => $t->created_at->toIso8601String(),
                ]),
            'reportHistory' => ClientReport::where('client_id', $retainer->client_id)
                ->orderByDesc('period_start')
                ->limit(12)
                ->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'period_label' => $r->period_label,
                    'status' => $r->status,
                    'sent_at' => $r->sent_at?->toIso8601String(),
                    'opens_count' => $r->opens_count,
                ]),
        ]);
    }

    /**
     * Jump to the current calendar month's period for this retainer's client,
     * creating it on demand if the scheduler hasn't rolled it over yet. This
     * guarantees a current-month (in-progress) report is always reachable.
     */
    public function current(RetainerPeriod $retainer): \Illuminate\Http\RedirectResponse
    {
        $retainer->loadMissing('client');

        $period = app(\App\Services\Reports\RetainerPeriodService::class)
            ->ensureCurrentPeriod($retainer->client);

        return redirect()->route('retainers.show', $period);
    }

    public function computeSnapshot(RetainerPeriod $retainer): \Illuminate\Http\RedirectResponse
    {
        app(RetainerHealthService::class)->computeAndPersistSnapshot(
            $retainer,
            $retainer->period_start,
            $retainer->period_end,
            persist: true,
        );

        return back()->with('success', 'Retainer snapshot refreshed.');
    }

    /**
     * Offboard a client's retainer: delete every retainer period for the
     * client (plus the AI-estimated time entries synthesized for those
     * reports, cached narratives, and stored PDFs) and disable recurring
     * invoicing so the daily sync doesn't recreate the current period
     * tomorrow. Tracked (manual) time entries and invoices survive — their
     * retainer_period_id FK nulls on delete.
     */
    public function destroy(RetainerPeriod $retainer): \Illuminate\Http\RedirectResponse
    {
        $retainer->loadMissing('client');
        $client = $retainer->client;
        $clientName = $client?->name ?? 'client';

        $periods = RetainerPeriod::where('client_id', $retainer->client_id)->get();
        $pdfGenerator = app(\App\Services\Reports\RetainerReportPdfGenerator::class);

        \Illuminate\Support\Facades\DB::transaction(function () use ($periods) {
            \App\Models\TimeEntry::whereIn('retainer_period_id', $periods->pluck('id'))
                ->where('source', 'ai_estimated')
                ->delete();

            RetainerPeriod::whereKey($periods->pluck('id'))->delete();
        });

        foreach ($periods as $period) {
            \Illuminate\Support\Facades\Cache::forget(sprintf('retainer.narrative.%d', $period->id));
            \Illuminate\Support\Facades\Cache::forget(\App\Jobs\RefreshRetainerReportJob::statusKey($period->id));

            $pdfPath = $pdfGenerator->getStoragePath($period);
            if (\Illuminate\Support\Facades\Storage::exists($pdfPath)) {
                \Illuminate\Support\Facades\Storage::delete($pdfPath);
            }
        }

        if ($client && $client->recurring_invoice_enabled) {
            $client->update(['recurring_invoice_enabled' => false]);
        }

        return redirect()->route('retainers.index')->with(
            'success',
            "Retainer removed for {$clientName}: {$periods->count()} period(s) deleted, recurring invoicing disabled.",
        );
    }
}
