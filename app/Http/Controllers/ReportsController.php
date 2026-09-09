<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReportsController extends Controller
{
    public function index()
    {
        return Inertia::render('Reports/Index');
    }

    /**
     * Time Report - hours by period, client, project
     */
    public function time(Request $request)
    {
        $startDate = $request->input('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->input('end_date', now()->format('Y-m-d'));
        $clientId = $request->input('client_id');
        $projectId = $request->input('project_id');
        $groupBy = $request->input('group_by', 'date'); // date, client, project, task

        $query = TimeEntry::whereBetween('spent_date', [$startDate, $endDate]);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }
        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $entries = $query->with(['client:id,name', 'project:id,name', 'task:id,title'])
            ->orderBy('spent_date', 'desc')
            ->get();

        // Group data based on selection
        $grouped = match ($groupBy) {
            'client' => $entries->groupBy('client_id')->map(fn ($items, $key) => [
                'label' => $items->first()->client?->name ?? 'No Client',
                'hours' => round($items->sum('hours'), 2),
                'billable_hours' => round($items->where('is_billable', true)->sum('hours'), 2),
                'amount' => round($items->sum('billable_amount'), 2),
            ])->values(),
            'project' => $entries->groupBy('project_id')->map(fn ($items, $key) => [
                'label' => $items->first()->project?->name ?? 'No Project',
                'hours' => round($items->sum('hours'), 2),
                'billable_hours' => round($items->where('is_billable', true)->sum('hours'), 2),
                'amount' => round($items->sum('billable_amount'), 2),
            ])->values(),
            'task' => $entries->groupBy('task_id')->map(fn ($items, $key) => [
                'label' => $items->first()->task?->title ?? 'No Task',
                'hours' => round($items->sum('hours'), 2),
                'billable_hours' => round($items->where('is_billable', true)->sum('hours'), 2),
                'amount' => round($items->sum('billable_amount'), 2),
            ])->values(),
            default => $entries->groupBy(fn ($e) => $e->spent_date->format('Y-m-d'))->map(fn ($items, $date) => [
                'label' => $date,
                'hours' => round($items->sum('hours'), 2),
                'billable_hours' => round($items->where('is_billable', true)->sum('hours'), 2),
                'amount' => round($items->sum('billable_amount'), 2),
            ])->values(),
        };

        $totals = [
            'hours' => round($entries->sum('hours'), 2),
            'billable_hours' => round($entries->where('is_billable', true)->sum('hours'), 2),
            'non_billable_hours' => round($entries->where('is_billable', false)->sum('hours'), 2),
            'amount' => round($entries->sum('billable_amount'), 2),
            'utilization' => $entries->sum('hours') > 0
                ? round(($entries->where('is_billable', true)->sum('hours') / $entries->sum('hours')) * 100, 1)
                : 0,
        ];

        return Inertia::render('Reports/Time', [
            'entries' => $entries->take(500)->map(fn ($e) => [
                'id' => $e->id,
                'date' => $e->spent_date->format('Y-m-d'),
                'hours' => $e->hours,
                'notes' => $e->notes,
                'is_billable' => $e->is_billable,
                'is_billed' => $e->is_billed,
                'client' => $e->client?->name,
                'project' => $e->project?->name,
                'task' => $e->task?->title,
                'amount' => $e->billable_amount,
            ]),
            'grouped' => $grouped,
            'totals' => $totals,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'client_id' => $clientId,
                'project_id' => $projectId,
                'group_by' => $groupBy,
            ],
            'clients' => Client::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'projects' => Project::where('status', 'active')->orderBy('name')->get(['id', 'name', 'client_id']),
        ]);
    }

    /**
     * Profitability Report - margins by client/project
     */
    public function profitability(Request $request)
    {
        $period = $request->input('period', 'month'); // month, quarter, year
        $year = $request->input('year', now()->year);
        $month = $request->input('month', now()->month);

        // Calculate date range based on period
        [$startDate, $endDate] = match ($period) {
            'quarter' => [
                now()->setYear($year)->quarter(ceil($month / 3))->startOfQuarter(),
                now()->setYear($year)->quarter(ceil($month / 3))->endOfQuarter(),
            ],
            'year' => [
                now()->setYear($year)->startOfYear(),
                now()->setYear($year)->endOfYear(),
            ],
            default => [
                now()->setYear($year)->setMonth($month)->startOfMonth(),
                now()->setYear($year)->setMonth($month)->endOfMonth(),
            ],
        };

        // Get time entries for period
        $entries = TimeEntry::whereBetween('spent_date', [$startDate, $endDate])
            ->with(['client:id,name', 'project:id,name'])
            ->get();

        // Get paid invoices for period
        $invoices = Invoice::where('status', Invoice::STATUS_PAID)
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->with('client:id,name')
            ->get();

        // Calculate by client
        $byClient = Client::where('status', 'active')
            ->get()
            ->map(function ($client) use ($entries, $invoices) {
                $clientEntries = $entries->where('client_id', $client->id);
                $clientInvoices = $invoices->where('client_id', $client->id);

                $hours = $clientEntries->sum('hours');
                $billableHours = $clientEntries->where('is_billable', true)->sum('hours');
                $cost = $clientEntries->sum(fn ($e) => $e->hours * ($e->cost_rate ?? 75));
                $revenue = $clientInvoices->sum('total');
                $profit = $revenue - $cost;

                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'hours' => round($hours, 1),
                    'billable_hours' => round($billableHours, 1),
                    'revenue' => round($revenue, 2),
                    'cost' => round($cost, 2),
                    'profit' => round($profit, 2),
                    'margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0,
                    'effective_rate' => $billableHours > 0 ? round($revenue / $billableHours, 2) : 0,
                ];
            })
            ->filter(fn ($c) => $c['hours'] > 0 || $c['revenue'] > 0)
            ->sortByDesc('revenue')
            ->values();

        // Calculate totals
        $totals = [
            'hours' => round($entries->sum('hours'), 1),
            'billable_hours' => round($entries->where('is_billable', true)->sum('hours'), 1),
            'revenue' => round($invoices->sum('total'), 2),
            'cost' => round($entries->sum(fn ($e) => $e->hours * ($e->cost_rate ?? 75)), 2),
            'profit' => 0,
            'margin' => 0,
        ];
        $totals['profit'] = round($totals['revenue'] - $totals['cost'], 2);
        $totals['margin'] = $totals['revenue'] > 0
            ? round(($totals['profit'] / $totals['revenue']) * 100, 1)
            : 0;

        return Inertia::render('Reports/Profitability', [
            'byClient' => $byClient,
            'totals' => $totals,
            'filters' => [
                'period' => $period,
                'year' => $year,
                'month' => $month,
            ],
            'dateRange' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
            ],
        ]);
    }

    /**
     * Payments Report - received payments history
     */
    public function payments(Request $request)
    {
        $startDate = $request->input('start_date', now()->startOfYear()->format('Y-m-d'));
        $endDate = $request->input('end_date', now()->format('Y-m-d'));
        $clientId = $request->input('client_id');

        $query = Payment::whereBetween('payment_date', [$startDate, $endDate])
            ->with(['invoice.client:id,name']);

        if ($clientId) {
            $query->whereHas('invoice', fn ($q) => $q->where('client_id', $clientId));
        }

        $payments = $query->orderBy('payment_date', 'desc')->get();

        // Group by month
        $byMonth = $payments->groupBy(fn ($p) => $p->payment_date->format('Y-m'))
            ->map(fn ($items, $month) => [
                'month' => $month,
                'count' => $items->count(),
                'total' => round($items->sum('amount'), 2),
            ])
            ->values();

        // Group by method
        $byMethod = $payments->groupBy('method')
            ->map(fn ($items, $method) => [
                'method' => $method,
                'count' => $items->count(),
                'total' => round($items->sum('amount'), 2),
            ])
            ->values();

        // Group by client
        $byClient = $payments->groupBy(fn ($p) => $p->invoice?->client_id)
            ->map(fn ($items) => [
                'client' => $items->first()->invoice?->client?->name ?? 'Unknown',
                'count' => $items->count(),
                'total' => round($items->sum('amount'), 2),
            ])
            ->sortByDesc('total')
            ->values();

        $totals = [
            'count' => $payments->count(),
            'total' => round($payments->sum('amount'), 2),
        ];

        return Inertia::render('Reports/Payments', [
            'payments' => $payments->take(200)->map(fn ($p) => [
                'id' => $p->id,
                'date' => $p->payment_date->format('Y-m-d'),
                'amount' => $p->amount,
                'method' => $p->method,
                'transaction_id' => $p->transaction_id,
                'reference' => $p->reference,
                'invoice_number' => $p->invoice?->number,
                'invoice_id' => $p->invoice_id,
                'client' => $p->invoice?->client?->name,
            ]),
            'byMonth' => $byMonth,
            'byMethod' => $byMethod,
            'byClient' => $byClient,
            'totals' => $totals,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'client_id' => $clientId,
            ],
            'clients' => Client::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * AR Aging Report - outstanding by 30/60/90 days
     */
    public function arAging()
    {
        $today = now();
        $unpaidStatuses = [
            Invoice::STATUS_SENT,
            Invoice::STATUS_VIEWED,
            Invoice::STATUS_PARTIAL,
            Invoice::STATUS_OVERDUE,
        ];

        $invoices = Invoice::whereIn('status', $unpaidStatuses)
            ->with('client:id,name')
            ->orderBy('due_date')
            ->get()
            ->map(function ($invoice) use ($today) {
                $daysOverdue = $invoice->due_date->lt($today)
                    ? $invoice->due_date->diffInDays($today)
                    : 0;

                $bucket = match (true) {
                    $daysOverdue === 0 && $invoice->due_date->gte($today) => 'current',
                    $daysOverdue <= 30 => '1-30',
                    $daysOverdue <= 60 => '31-60',
                    $daysOverdue <= 90 => '61-90',
                    default => '90+',
                };

                return [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'client' => $invoice->client?->name,
                    'client_id' => $invoice->client_id,
                    'issue_date' => $invoice->issue_date->format('Y-m-d'),
                    'due_date' => $invoice->due_date->format('Y-m-d'),
                    'total' => $invoice->total,
                    'amount_due' => $invoice->amount_due,
                    'days_overdue' => $daysOverdue,
                    'bucket' => $bucket,
                    'status' => $invoice->status,
                ];
            });

        // Summary by bucket
        $buckets = ['current', '1-30', '31-60', '61-90', '90+'];
        $byBucket = collect($buckets)->mapWithKeys(function ($bucket) use ($invoices) {
            $bucketInvoices = $invoices->where('bucket', $bucket);

            return [$bucket => [
                'count' => $bucketInvoices->count(),
                'amount' => round($bucketInvoices->sum('amount_due'), 2),
            ]];
        });

        // Summary by client
        $byClient = $invoices->groupBy('client_id')
            ->map(fn ($items) => [
                'client' => $items->first()['client'] ?? 'Unknown',
                'count' => $items->count(),
                'total' => round($items->sum('amount_due'), 2),
                'oldest_days' => $items->max('days_overdue'),
            ])
            ->sortByDesc('total')
            ->values();

        $totals = [
            'count' => $invoices->count(),
            'total' => round($invoices->sum('amount_due'), 2),
            'overdue_count' => $invoices->where('days_overdue', '>', 0)->count(),
            'overdue_amount' => round($invoices->where('days_overdue', '>', 0)->sum('amount_due'), 2),
        ];

        return Inertia::render('Reports/ArAging', [
            'invoices' => $invoices->values(),
            'byBucket' => $byBucket,
            'byClient' => $byClient,
            'totals' => $totals,
        ]);
    }

    /**
     * Unbilled Time Report - billable time not yet invoiced
     */
    public function unbilledTime(Request $request)
    {
        $clientId = $request->input('client_id');
        $projectId = $request->input('project_id');

        $query = TimeEntry::where('is_billable', true)
            ->where('is_billed', false)
            ->with(['client:id,name', 'project:id,name', 'task:id,title']);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }
        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $entries = $query->orderBy('spent_date', 'desc')->get();

        // Group by client
        $byClient = $entries->groupBy('client_id')
            ->map(fn ($items) => [
                'client_id' => $items->first()->client_id,
                'client' => $items->first()->client?->name ?? 'No Client',
                'hours' => round($items->sum('hours'), 2),
                'amount' => round($items->sum('billable_amount'), 2),
                'entry_count' => $items->count(),
                'oldest_date' => $items->min('spent_date')?->format('Y-m-d'),
            ])
            ->sortByDesc('amount')
            ->values();

        // Group by project
        $byProject = $entries->groupBy('project_id')
            ->map(fn ($items) => [
                'project_id' => $items->first()->project_id,
                'project' => $items->first()->project?->name ?? 'No Project',
                'client' => $items->first()->client?->name ?? 'No Client',
                'hours' => round($items->sum('hours'), 2),
                'amount' => round($items->sum('billable_amount'), 2),
                'entry_count' => $items->count(),
            ])
            ->sortByDesc('amount')
            ->values();

        $totals = [
            'hours' => round($entries->sum('hours'), 2),
            'amount' => round($entries->sum('billable_amount'), 2),
            'entry_count' => $entries->count(),
            'client_count' => $entries->pluck('client_id')->unique()->count(),
        ];

        return Inertia::render('Reports/UnbilledTime', [
            'entries' => $entries->take(500)->map(fn ($e) => [
                'id' => $e->id,
                'date' => $e->spent_date->format('Y-m-d'),
                'hours' => $e->hours,
                'notes' => $e->notes,
                'client' => $e->client?->name,
                'client_id' => $e->client_id,
                'project' => $e->project?->name,
                'task' => $e->task?->title,
                'amount' => $e->billable_amount,
            ]),
            'byClient' => $byClient,
            'byProject' => $byProject,
            'totals' => $totals,
            'filters' => [
                'client_id' => $clientId,
                'project_id' => $projectId,
            ],
            'clients' => Client::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'projects' => Project::where('status', 'active')->orderBy('name')->get(['id', 'name', 'client_id']),
        ]);
    }
}
