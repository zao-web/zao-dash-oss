<?php

namespace App\Http\Controllers;

use App\Models\ClientContact;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClientPortalController extends Controller
{
    /**
     * Resolve the portal client from the middleware (supports impersonation).
     */
    private function portalClient(Request $request): \App\Models\Client
    {
        return $request->attributes->get('portal_client') ?? $request->user()->client;
    }

    /**
     * Client portal dashboard.
     */
    public function dashboard(Request $request)
    {
        $client = $this->portalClient($request);

        $activeProjects = $client->projects()
            ->whereIn('status', ['active', 'in_progress'])
            ->with(['milestones', 'tasks:id,project_id,status'])
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'status' => $p->status,
                'progress' => $p->progress_percent,
                'next_milestone' => $p->milestones->where('status', 'pending')->first()?->name,
                'due_date' => $p->end_date?->format('M d, Y'),
            ]);

        $recentInvoices = Invoice::where('client_id', $client->id)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->orderByDesc('issue_date')
            ->limit(5)
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'number' => $i->number,
                'date' => $i->issue_date->format('M d, Y'),
                'amount' => (float) $i->total,
                'balance' => (float) $i->amount_due,
                'status' => $i->status,
                'public_url' => $i->public_url,
            ]);

        return Inertia::render('Portal/Dashboard', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'activeProjects' => $activeProjects,
            'recentInvoices' => $recentInvoices,
            'stats' => [
                'total_projects' => $client->projects()->count(),
                'active_projects' => $activeProjects->count(),
                'open_invoices' => $recentInvoices->where('status', 'open')->count(),
            ],
        ]);
    }

    /**
     * List client's projects.
     */
    public function projects(Request $request)
    {
        $client = $this->portalClient($request);

        $projects = $client->projects()
            ->with(['milestones', 'tasks:id,project_id,status'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'status' => $p->status,
                'progress' => $p->progress_percent,
                'start_date' => $p->start_date?->format('M d, Y'),
                'due_date' => $p->end_date?->format('M d, Y'),
                'milestones' => $p->milestones->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'status' => $m->status,
                    'due_date' => $m->due_date?->format('M d'),
                ]),
                'recent_updates' => [],
            ]);

        return Inertia::render('Portal/Projects', [
            'projects' => $projects,
        ]);
    }

    /**
     * Show a specific project.
     */
    public function projectShow(Request $request, Project $project)
    {
        $client = $this->portalClient($request);

        // Ensure project belongs to client
        if ($project->client_id !== $client->id) {
            abort(403);
        }

        $project->load(['milestones', 'tasks.assignee']);

        return Inertia::render('Portal/ProjectShow', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status,
                'progress' => $project->progress_percent,
                'start_date' => $project->start_date?->format('M d, Y'),
                'start_date_raw' => $project->start_date?->toDateString(),
                'due_date' => $project->end_date?->format('M d, Y'),
                'end_date_raw' => $project->end_date?->toDateString(),
                'timeline_status' => $project->timeline_status,
                'tasks' => $project->tasks->map(function ($t) {
                    $assignee = $t->assignee_info;

                    // Mask agent assignees as "Zao Team" for client view
                    if ($assignee && $assignee['type'] === 'agent') {
                        $assignee = ['id' => null, 'name' => 'Zao Team', 'type' => 'user'];
                    }

                    return [
                        'id' => $t->id,
                        'title' => $t->title,
                        'status' => $t->status,
                        'priority' => $t->priority,
                        'milestone_id' => $t->milestone_id,
                        'assignee' => $assignee,
                        'due_date' => $t->due_date?->format('M d'),
                        'estimated_hours' => $t->estimated_hours,
                    ];
                }),
                'milestones' => $project->milestones->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'description' => $m->description,
                    'status' => $m->status,
                    'due_date' => $m->due_date?->format('M d, Y'),
                    'completed_at' => $m->completed_at?->format('M d, Y'),
                    'tasks_count' => $project->tasks->where('milestone_id', $m->id)->count(),
                    'completed_tasks_count' => $project->tasks->where('milestone_id', $m->id)->where('status', 'completed')->count(),
                ]),
            ],
            'stats' => [
                'total_tasks' => $project->tasks->count(),
                'completed_tasks' => $project->tasks->where('status', 'completed')->count(),
                'in_progress_tasks' => $project->tasks->where('status', 'in_progress')->count(),
                'pending_tasks' => $project->tasks->where('status', 'pending')->count(),
                'review_tasks' => $project->tasks->where('status', 'review')->count(),
            ],
            'team' => User::whereIn('role', ['owner', 'admin', 'staff'])
                ->select('id', 'name')
                ->orderBy('name')
                ->get(),
            'clientContacts' => ClientContact::where('client_id', $client->id)
                ->whereNotIn('email', User::pluck('email'))
                ->orderBy('name')
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                ]),
        ]);
    }

    /**
     * List client's invoices.
     */
    public function invoices(Request $request)
    {
        $client = $this->portalClient($request);

        $invoices = Invoice::where('client_id', $client->id)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->orderByDesc('issue_date')
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'number' => $i->number,
                'date' => $i->issue_date->format('M d, Y'),
                'due_date' => $i->due_date?->format('M d, Y'),
                'amount' => (float) $i->total,
                'balance' => (float) $i->amount_due,
                'status' => $i->status,
                'public_url' => $i->public_url,
                'is_overdue' => $i->isOverdue(),
                'days_overdue' => $i->days_overdue,
            ]);

        $summary = [
            'total_billed' => $invoices->sum('amount'),
            'total_paid' => $invoices->sum('amount') - $invoices->sum('balance'),
            'outstanding' => $invoices->sum('balance'),
        ];

        return Inertia::render('Portal/Invoices', [
            'invoices' => $invoices,
            'summary' => $summary,
        ]);
    }

    /**
     * Show client's account statement.
     */
    public function statement(Request $request)
    {
        $client = $this->portalClient($request);

        // Get all invoices for statement
        $invoices = Invoice::where('client_id', $client->id)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->orderByDesc('issue_date')
            ->with('payments')
            ->get();

        // Build statement entries (invoices and payments interleaved)
        $entries = collect();

        foreach ($invoices as $invoice) {
            // Add invoice entry
            $entries->push([
                'date' => $invoice->issue_date,
                'type' => 'invoice',
                'description' => "Invoice #{$invoice->number}".($invoice->subject ? " - {$invoice->subject}" : ''),
                'amount' => (float) $invoice->total,
                'balance_change' => (float) $invoice->total,
            ]);

            // Add payment entries
            foreach ($invoice->payments as $payment) {
                if ($payment->status === 'completed') {
                    $entries->push([
                        'date' => $payment->payment_date,
                        'type' => 'payment',
                        'description' => "Payment received - Invoice #{$invoice->number}",
                        'amount' => (float) $payment->amount,
                        'balance_change' => -1 * (float) $payment->amount,
                    ]);
                }
            }
        }

        // Sort by date descending
        $entries = $entries->sortByDesc('date')->values();

        // Calculate running balance
        $runningBalance = 0;
        $entriesWithBalance = $entries->reverse()->map(function ($entry) use (&$runningBalance) {
            $runningBalance += $entry['balance_change'];
            $entry['running_balance'] = $runningBalance;
            $entry['date'] = $entry['date']->format('M d, Y');

            return $entry;
        })->reverse()->values();

        $summary = [
            'total_billed' => $invoices->sum('total'),
            'total_paid' => $invoices->sum('amount_paid'),
            'current_balance' => $invoices->sum('amount_due'),
        ];

        return Inertia::render('Portal/Statement', [
            'entries' => $entriesWithBalance,
            'summary' => $summary,
            'client' => [
                'name' => $client->name,
            ],
        ]);
    }
}
