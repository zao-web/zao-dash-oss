<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\InvoiceReminderSchedule;
use App\Models\Payment;
use App\Models\TimeEntry;
use App\Services\Invoicing\InvoiceService;
use App\Services\Invoicing\PdfInvoiceGenerator;
use App\Services\PayPal\PayPalService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class InvoiceController extends Controller
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected PdfInvoiceGenerator $pdfGenerator,
        protected PayPalService $paypalService
    ) {}

    /**
     * Display a listing of invoices (admin).
     */
    public function index(Request $request)
    {
        // Get selected year (default to current year)
        $year = (int) ($request->input('year', now()->year));
        $showOpen = $request->input('view', 'open') === 'open';

        // Build base query for listing
        $query = Invoice::with(['client', 'project']);

        // Filter by view (open vs all)
        if ($showOpen) {
            $query->whereIn('status', [
                Invoice::STATUS_DRAFT,
                Invoice::STATUS_SENT,
                Invoice::STATUS_VIEWED,
                Invoice::STATUS_PARTIAL,
                Invoice::STATUS_OVERDUE,
            ]);
        }

        // Order: drafts first, then by issue date desc
        $query->orderByRaw("CASE WHEN status = 'draft' THEN 0 ELSE 1 END")
            ->orderBy('issue_date', 'desc');

        $invoices = $query->paginate(50);

        // Add computed attributes
        $invoices->getCollection()->transform(function ($invoice) {
            $invoice->days_to_pay = $invoice->days_to_pay;
            $invoice->days_overdue = $invoice->days_overdue;

            return $invoice;
        });

        // Summary stats (for all open invoices, regardless of pagination)
        $openStatuses = [
            Invoice::STATUS_DRAFT,
            Invoice::STATUS_SENT,
            Invoice::STATUS_VIEWED,
            Invoice::STATUS_PARTIAL,
            Invoice::STATUS_OVERDUE,
        ];

        $openInvoices = Invoice::whereIn('status', $openStatuses)->get();

        $summary = [
            'total_open' => $openInvoices->sum('amount_due'),
            'total_open_count' => $openInvoices->count(),
            'overdue_amount' => $openInvoices->where('status', Invoice::STATUS_OVERDUE)->sum('amount_due'),
            'overdue_count' => $openInvoices->where('status', Invoice::STATUS_OVERDUE)->count(),
            'sent_amount' => $openInvoices->whereIn('status', [Invoice::STATUS_SENT, Invoice::STATUS_VIEWED])->sum('amount_due'),
            'sent_count' => $openInvoices->whereIn('status', [Invoice::STATUS_SENT, Invoice::STATUS_VIEWED])->count(),
            'draft_amount' => $openInvoices->where('status', Invoice::STATUS_DRAFT)->sum('total'),
            'draft_count' => $openInvoices->where('status', Invoice::STATUS_DRAFT)->count(),
            'total_paid_year' => Invoice::where('status', Invoice::STATUS_PAID)
                ->whereYear('paid_at', $year)
                ->sum('total'),
        ];

        // Monthly chart data for selected year
        $chartData = [];
        for ($month = 1; $month <= 12; $month++) {
            $openAmount = Invoice::whereIn('status', $openStatuses)
                ->whereYear('issue_date', $year)
                ->whereMonth('issue_date', $month)
                ->sum('amount_due');

            $paidAmount = Invoice::where('status', Invoice::STATUS_PAID)
                ->whereYear('paid_at', $year)
                ->whereMonth('paid_at', $month)
                ->sum('total');

            $chartData[] = [
                'month' => Carbon::create($year, $month, 1)->format('M'),
                'open' => round($openAmount, 2),
                'paid' => round($paidAmount, 2),
            ];
        }

        // Calculate table totals for current view
        $tableTotal = $showOpen
            ? $openInvoices->sum('amount_due')
            : $invoices->getCollection()->sum(fn ($i) => (float) $i->amount_due);

        // Get available years for navigation
        $years = Invoice::whereNotNull('issue_date')
            ->get()
            ->pluck('issue_date')
            ->map(fn ($d) => (int) $d->format('Y'))
            ->unique()
            ->sortDesc()
            ->values()
            ->toArray();

        if (empty($years)) {
            $years = [now()->year];
        }

        // Upcoming recurring invoices
        $recurringClients = Client::where('recurring_invoice_enabled', true)
            ->where('recurring_invoice_amount', '>', 0)
            ->where('status', 'active')
            ->get()
            ->map(function ($client) {
                $day = $client->recurring_invoice_day ?? 1;

                $generatedThisMonth = $client->recurring_invoice_last_generated
                    && Carbon::parse($client->recurring_invoice_last_generated)->isSameMonth(now());

                // Next date is always the next FUTURE billing day
                if ($generatedThisMonth || now()->day >= $day) {
                    // Already generated or billing day passed → next month
                    $nextMonth = now()->addMonth();
                    $nextDate = $nextMonth->setDay(min($day, $nextMonth->daysInMonth));
                } else {
                    // Billing day hasn't come yet this month
                    $nextDate = now()->setDay(min($day, now()->daysInMonth));
                }

                return [
                    'client_id' => $client->id,
                    'client_name' => $client->name,
                    'amount' => (float) $client->recurring_invoice_amount,
                    'next_date' => $nextDate->format('M j, Y'),
                    'payment_terms' => $client->payment_terms ?? 'Net 30',
                    'auto_send' => $client->recurring_invoice_auto_send,
                    'generated_this_month' => $generatedThisMonth,
                ];
            })
            ->sortBy('next_date')
            ->values();

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'summary' => $summary,
            'chartData' => $chartData,
            'tableTotal' => $tableTotal,
            'year' => $year,
            'years' => $years,
            'view' => $showOpen ? 'open' : 'all',
            'recurringClients' => $recurringClients,
        ]);
    }

    /**
     * Show a single invoice (admin).
     */
    public function show(Invoice $invoice)
    {
        $invoice->load(['client.contacts', 'project', 'lines', 'payments', 'reminders', 'activities.user', 'retainerPeriod']);

        $contacts = $invoice->client->contacts->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'email' => $c->email,
            'role' => $c->role,
            'is_primary' => $c->is_primary,
        ]);

        // Add billing email as a selectable option if it differs from contacts
        $billingEmail = $invoice->client->billing_email;
        $contactEmails = $contacts->pluck('email')->toArray();
        if ($billingEmail && ! in_array($billingEmail, $contactEmails)) {
            $contacts->prepend([
                'id' => null,
                'name' => 'Billing Email',
                'email' => $billingEmail,
                'role' => 'billing',
                'is_primary' => false,
            ]);
        }

        $retainerReportUrl = $invoice->retainerPeriod
            ? \App\Http\Controllers\RetainerReportController::signedUrlFor($invoice->retainerPeriod)
            : null;

        return Inertia::render('Invoices/Show', [
            'invoice' => array_merge($invoice->toArray(), [
                'days_to_pay' => $invoice->days_to_pay,
                'days_overdue' => $invoice->days_overdue,
                'public_url' => $invoice->public_url,
                'retainer_report_url' => $retainerReportUrl,
            ]),
            'contacts' => $contacts->values(),
        ]);
    }

    /**
     * Download PDF of invoice (admin).
     */
    public function downloadPdf(Invoice $invoice)
    {
        $storagePath = "invoices/{$invoice->number}.pdf";

        // Generate if doesn't exist
        if (! Storage::exists($storagePath)) {
            $this->pdfGenerator->generateAndStore($invoice);
        }

        // Check again after generation attempt
        if (! Storage::exists($storagePath)) {
            // Fallback to HTML
            $html = $this->pdfGenerator->renderHtml($invoice);

            return response($html, 200, [
                'Content-Type' => 'text/html',
            ]);
        }

        return Storage::download($storagePath, "Invoice-{$invoice->number}.pdf");
    }

    /**
     * Preview PDF in browser (admin).
     */
    public function previewPdf(Invoice $invoice)
    {
        $storagePath = "invoices/{$invoice->number}.pdf";

        // Generate if doesn't exist
        if (! Storage::exists($storagePath)) {
            $this->pdfGenerator->generateAndStore($invoice);
        }

        if (! Storage::exists($storagePath)) {
            // Fallback to HTML preview
            $html = $this->pdfGenerator->renderHtml($invoice);

            return response($html, 200, [
                'Content-Type' => 'text/html',
            ]);
        }

        return response(Storage::get($storagePath), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Invoice-'.$invoice->number.'.pdf"',
        ]);
    }

    /**
     * Public invoice view (client-facing, no auth required).
     */
    public function publicView(string $invoiceNumber, Request $request)
    {
        $invoice = Invoice::where('number', $invoiceNumber)->firstOrFail();

        // Verify token
        $token = $request->query('token');
        if (! $token || ! hash_equals($invoice->public_token, $token)) {
            abort(403, 'Invalid invoice link');
        }

        // Mark as viewed
        $this->invoiceService->markAsViewed($invoice);

        $invoice->load(['client', 'lines']);

        $paymentLink = null;
        if ($invoice->amount_due > 0 && $this->paypalService->isConfigured()) {
            if (! $invoice->paypal_invoice_id) {
                try {
                    \Illuminate\Support\Facades\Log::info('Creating PayPal invoice for public view', [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->number,
                        'amount_due' => $invoice->amount_due,
                    ]);

                    $this->paypalService->createInvoice($invoice);
                    $invoice->refresh();

                    \Illuminate\Support\Facades\Log::info('PayPal invoice created successfully', [
                        'invoice_id' => $invoice->id,
                        'paypal_invoice_id' => $invoice->paypal_invoice_id,
                    ]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to auto-create PayPal invoice', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            if ($invoice->paypal_invoice_id) {
                $paymentLink = $this->paypalService->getPaymentLink($invoice);

                \Illuminate\Support\Facades\Log::info('Payment link retrieval result', [
                    'invoice_id' => $invoice->id,
                    'paypal_invoice_id' => $invoice->paypal_invoice_id,
                    'has_payment_link' => ! empty($paymentLink),
                ]);
            } else {
                \Illuminate\Support\Facades\Log::warning('No PayPal invoice ID after creation attempt', [
                    'invoice_id' => $invoice->id,
                ]);
            }
        } else {
            \Illuminate\Support\Facades\Log::info('Skipping PayPal for invoice', [
                'invoice_id' => $invoice->id,
                'amount_due' => $invoice->amount_due,
                'paypal_configured' => $this->paypalService->isConfigured(),
            ]);
        }

        return view('invoices.show', [
            'invoice' => $invoice,
            'client' => $invoice->client,
            'lines' => $invoice->lines,
            'paymentUrl' => $paymentLink,
            'isPdf' => false,
            'branding' => [
                'primary_color' => $invoice->client->brand_color ?? '#2563eb',
                'logo_url' => $invoice->client->logo_url,
                'footer' => $invoice->client->invoice_footer ?? null,
            ],
            'company' => [
                'name' => config('app.company_name', 'Zao'),
                'email' => config('app.company_email', 'billing@example.com'),
                'address' => config('app.company_address', ''),
                'phone' => config('app.company_phone', ''),
                'logo_url' => config('app.company_logo_url', ''),
            ],
        ]);
    }

    /**
     * Download PDF from public link (client-facing).
     */
    public function publicDownloadPdf(string $invoiceNumber, Request $request)
    {
        $invoice = Invoice::where('number', $invoiceNumber)->firstOrFail();

        // Verify token
        $token = $request->query('token');
        if (! $token || ! hash_equals($invoice->public_token, $token)) {
            abort(403, 'Invalid invoice link');
        }

        return $this->downloadPdf($invoice);
    }

    /**
     * Send invoice to client.
     */
    public function send(Request $request, Invoice $invoice)
    {
        $validated = $request->validate([
            'recipients' => 'nullable|array|min:1',
            'recipients.*' => 'email',
            'is_test' => 'nullable|boolean',
        ]);

        $isTest = ! empty($validated['is_test']);

        // Create PayPal invoice if not exists (skip for test sends)
        if (! $isTest && ! $invoice->paypal_invoice_id && $this->paypalService->isConfigured()) {
            try {
                $this->paypalService->createInvoice($invoice);
                $invoice->refresh();
            } catch (\Exception $e) {
                // Continue without PayPal - payment link will be null
            }
        }

        // Pre-generate PDF for attachment (ensures it's ready before email)
        $this->pdfGenerator->generateAndStore($invoice);

        // Mark as sent and schedule reminders (skip for test sends)
        if (! $isTest) {
            $this->invoiceService->markAsSent($invoice);
        }

        // Get payment link for email
        $paymentLink = $invoice->paypal_invoice_id
            ? $this->paypalService->getPaymentLink($invoice)
            : null;

        // Determine recipients
        if ($isTest) {
            $recipients = [$request->user()->email];
        } elseif (! empty($validated['recipients'])) {
            $recipients = $validated['recipients'];
        } else {
            $fallback = $invoice->client->billing_email
                ?? $invoice->client->contacts()->first()?->email;
            $recipients = $fallback ? [$fallback] : [];
        }

        // Send email to each recipient
        foreach ($recipients as $email) {
            \Mail::to($email)->send(
                new \App\Mail\InvoiceSentMail($invoice, $paymentLink)
            );
        }

        // Log activity
        $invoice->activities()->create([
            'type' => $isTest ? 'test_sent' : 'sent',
            'description' => $isTest
                ? 'Test email sent to '.$request->user()->email
                : 'Invoice emailed to '.implode(', ', $recipients),
            'user_id' => $request->user()->id,
            'metadata' => ['recipients' => $recipients],
        ]);

        $message = $isTest
            ? 'Test email sent to '.$request->user()->email
            : 'Invoice sent to '.implode(', ', $recipients);

        return back()->with('success', $message);
    }

    /**
     * Regenerate PDF (after edits).
     */
    public function regeneratePdf(Invoice $invoice)
    {
        $messages = [];

        // Delete existing PDF
        $this->pdfGenerator->deletePdf($invoice);

        // Explicitly try to create PayPal invoice if needed
        if (! $invoice->paypal_invoice_id && $invoice->amount_due > 0) {
            if ($this->paypalService->isConfigured()) {
                try {
                    $this->paypalService->createInvoice($invoice);
                    $invoice->refresh();
                    $messages[] = 'PayPal invoice created';
                } catch (\Exception $e) {
                    $messages[] = 'PayPal failed: '.$e->getMessage();
                }
            } else {
                $messages[] = 'PayPal not configured';
            }
        }

        // Generate new PDF
        $this->pdfGenerator->generateAndStore($invoice);
        $messages[] = 'PDF regenerated';

        return back()->with('success', implode('. ', $messages));
    }

    /**
     * Show the invoice creation form.
     */
    public function create(Request $request)
    {
        $clients = Client::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'default_tax_rate', 'default_hourly_rate', 'payment_terms']);

        $selectedClientId = $request->query('client_id');
        $selectedProjectId = $request->query('project_id');

        // Get unbilled time entries if client selected
        $unbilledTime = [];
        $projects = [];

        if ($selectedClientId) {
            $client = Client::find($selectedClientId);
            if ($client) {
                $projects = $client->projects()
                    ->orderBy('name')
                    ->get(['id', 'name', 'slug']);

                $query = TimeEntry::where('client_id', $selectedClientId)
                    ->where('is_billable', true)
                    ->where('is_billed', false)
                    ->whereNotNull('hourly_rate')
                    ->where('hours', '>', 0)
                    ->with(['project:id,name', 'task:id,title'])
                    ->orderBy('spent_date');

                if ($selectedProjectId) {
                    $query->where('project_id', $selectedProjectId);
                }

                $unbilledTime = $query->get()->map(fn ($e) => [
                    'id' => $e->id,
                    'date' => $e->spent_date->format('M j, Y'),
                    'hours' => $e->hours,
                    'rate' => $e->hourly_rate,
                    'amount' => round($e->hours * $e->hourly_rate, 2),
                    'notes' => $e->notes,
                    'project' => $e->project?->name,
                    'task' => $e->task?->title,
                ]);
            }
        }

        return Inertia::render('Invoices/Create', [
            'clients' => $clients,
            'projects' => $projects,
            'unbilledTime' => $unbilledTime,
            'selectedClientId' => $selectedClientId ? (int) $selectedClientId : null,
            'selectedProjectId' => $selectedProjectId ? (int) $selectedProjectId : null,
            'groupModes' => [
                ['value' => 'individual', 'label' => 'Individual entries'],
                ['value' => 'project', 'label' => 'Group by project'],
                ['value' => 'task', 'label' => 'Group by task'],
                ['value' => 'date', 'label' => 'Group by date'],
            ],
        ]);
    }

    /**
     * Store a new invoice.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'project_id' => 'nullable|exists:projects,id',
            'subject' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'due_date' => 'required|date',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'time_entry_ids' => 'nullable|array',
            'time_entry_ids.*' => 'exists:time_entries,id',
            'group_mode' => 'nullable|in:individual,project,task,date',
            'lines' => 'nullable|array',
            'lines.*.type' => 'required|in:time,fixed,expense,discount',
            'lines.*.description' => 'required|string',
            'lines.*.quantity' => 'required|numeric|min:0',
            'lines.*.unit_price' => 'required|numeric',
        ]);

        $client = Client::findOrFail($validated['client_id']);

        return DB::transaction(function () use ($validated, $client) {
            // If time entries selected, use InvoiceService
            if (! empty($validated['time_entry_ids'])) {
                $timeEntries = TimeEntry::whereIn('id', $validated['time_entry_ids'])->get();

                $invoice = $this->invoiceService->createFromTimeEntries($client, $timeEntries, [
                    'group_mode' => $validated['group_mode'] ?? 'individual',
                    'subject' => $validated['subject'],
                    'notes' => $validated['notes'],
                    'due_date' => Carbon::parse($validated['due_date']),
                    'project_id' => $validated['project_id'],
                ]);

                $invoice->update([
                    'internal_notes' => $validated['internal_notes'],
                    'tax_rate' => $validated['tax_rate'] ?? $client->default_tax_rate ?? 0,
                ]);
            } else {
                // Create blank invoice with manual lines (with retry for unique constraint)
                $invoice = Invoice::createWithUniqueNumber([
                    'client_id' => $client->id,
                    'project_id' => $validated['project_id'],
                    'subject' => $validated['subject'],
                    'notes' => $validated['notes'],
                    'internal_notes' => $validated['internal_notes'],
                    'status' => Invoice::STATUS_DRAFT,
                    'subtotal' => 0,
                    'tax_rate' => $validated['tax_rate'] ?? $client->default_tax_rate ?? 0,
                    'tax_amount' => 0,
                    'total' => 0,
                    'amount_paid' => 0,
                    'amount_due' => 0,
                    'issue_date' => now(),
                    'due_date' => Carbon::parse($validated['due_date']),
                    'payment_terms' => InvoiceService::derivePaymentTerms(Carbon::parse($validated['due_date']), $client->payment_terms),
                    'currency' => 'USD',
                ]);
            }

            // Add manual lines if provided
            if (! empty($validated['lines'])) {
                $sortOrder = $invoice->lines()->max('sort_order') ?? -1;

                foreach ($validated['lines'] as $lineData) {
                    $invoice->lines()->create([
                        'type' => $lineData['type'],
                        'description' => $lineData['description'],
                        'quantity' => $lineData['quantity'],
                        'unit_price' => $lineData['unit_price'],
                        'taxable' => $lineData['type'] !== 'expense',
                        'sort_order' => ++$sortOrder,
                    ]);
                }
            }

            // Recalculate totals
            $invoice->recalculateTotals();

            $invoice->activities()->create([
                'type' => 'created',
                'description' => 'Invoice created',
                'user_id' => request()->user()->id,
            ]);

            return redirect()->route('invoices.show', $invoice)
                ->with('success', 'Invoice created successfully');
        });
    }

    /**
     * Show the invoice edit form.
     */
    public function edit(Invoice $invoice)
    {
        $invoice->load(['client', 'project', 'lines', 'payments']);

        $clients = Client::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'default_tax_rate', 'default_hourly_rate', 'payment_terms']);

        $projects = $invoice->client->projects()
            ->orderBy('name')
            ->get(['id', 'name']);

        // Can notify client for any non-draft invoice
        $canNotifyClient = in_array($invoice->status, [
            Invoice::STATUS_SENT,
            Invoice::STATUS_VIEWED,
            Invoice::STATUS_PARTIAL,
            Invoice::STATUS_OVERDUE,
        ]);

        // Resolve effective PayPal recipient email for display
        $paypalRecipientEmail = $invoice->recipient_email
            ?? $invoice->client->billing_email
            ?? $invoice->client->contacts()->whereNotNull('email')->value('email');

        return Inertia::render('Invoices/Edit', [
            'invoice' => array_merge($invoice->toArray(), [
                'public_url' => $invoice->public_url,
            ]),
            'clients' => $clients,
            'projects' => $projects,
            'canEdit' => $invoice->isEditable(),
            'canEditAmounts' => $invoice->isEditable(),
            'canEditPartial' => $invoice->isEditable(),
            'canNotifyClient' => $canNotifyClient,
            'paypalRecipientEmail' => $paypalRecipientEmail,
        ]);
    }

    /**
     * Update an invoice.
     */
    public function update(Request $request, Invoice $invoice)
    {
        $validated = $request->validate([
            'subject' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'issue_date' => 'nullable|date',
            'due_date' => 'required|date',
            'payment_terms' => 'nullable|string|max:50',
            'po_number' => 'nullable|string|max:255',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'project_id' => 'nullable|exists:projects,id',
            'lines' => 'nullable|array',
            'lines.*.id' => 'nullable|exists:invoice_lines,id',
            'lines.*.type' => 'required|in:time,fixed,expense,discount',
            'lines.*.description' => 'required|string',
            'lines.*.details' => 'nullable|string',
            'lines.*.quantity' => 'required|numeric|min:0',
            'lines.*.unit' => 'nullable|string|max:50',
            'lines.*.unit_price' => 'required|numeric',
            'lines.*.delete' => 'nullable|boolean',
            'recipient_email' => 'nullable|email|max:255',
            'notify_client' => 'nullable|boolean',
            'update_summary' => 'nullable|string|max:500',
        ]);

        // Allow full edit on any editable status (everything except paid/cancelled)
        $canFullEdit = $invoice->isEditable();

        // Check if we should notify the client (only for sent invoices)
        $shouldNotify = ! empty($validated['notify_client'])
            && in_array($invoice->status, [
                Invoice::STATUS_SENT,
                Invoice::STATUS_VIEWED,
                Invoice::STATUS_PARTIAL,
                Invoice::STATUS_OVERDUE,
            ]);

        $updateSummary = $validated['update_summary'] ?? null;

        return DB::transaction(function () use ($validated, $invoice, $canFullEdit, $shouldNotify, $updateSummary) {
            // Update invoice fields. The edit form exposes an explicit
            // payment-terms select, so an operator's choice wins over the
            // derived label (which measures the due date against *today* —
            // meaningless when editing an old invoice).
            $dueDate = Carbon::parse($validated['due_date']);
            $invoiceData = [
                'subject' => $validated['subject'],
                'notes' => $validated['notes'],
                'internal_notes' => $validated['internal_notes'],
                'due_date' => $dueDate,
                'payment_terms' => $validated['payment_terms']
                    ?? InvoiceService::derivePaymentTerms($dueDate, $invoice->client->payment_terms),
                'project_id' => $validated['project_id'],
                'tax_rate' => $canFullEdit ? ($validated['tax_rate'] ?? $invoice->tax_rate) : $invoice->tax_rate,
            ];

            if (! empty($validated['issue_date'])) {
                $invoiceData['issue_date'] = Carbon::parse($validated['issue_date']);
            }

            if (array_key_exists('po_number', $validated)) {
                $invoiceData['po_number'] = $validated['po_number'];
            }

            if ($canFullEdit && array_key_exists('recipient_email', $validated)) {
                $invoiceData['recipient_email'] = $validated['recipient_email'];
            }

            $invoice->update($invoiceData);

            // Update lines (only on drafts)
            if ($canFullEdit && ! empty($validated['lines'])) {
                $existingIds = [];

                foreach ($validated['lines'] as $index => $lineData) {
                    if (! empty($lineData['delete'])) {
                        if (! empty($lineData['id'])) {
                            $invoice->lines()->whereKey($lineData['id'])->delete();
                        }

                        continue;
                    }

                    if (! empty($lineData['id'])) {
                        // Update via the model (not a bulk query) so the
                        // saving hook recomputes the line amount — otherwise
                        // recalculateTotals() sums stale amounts. Scoped to
                        // this invoice's own lines.
                        $line = $invoice->lines()->whereKey($lineData['id'])->first();
                        if ($line) {
                            $line->fill([
                                'type' => $lineData['type'],
                                'description' => $lineData['description'],
                                'details' => $lineData['details'] ?? null,
                                'quantity' => $lineData['quantity'],
                                'unit' => $lineData['unit'] ?? null,
                                'unit_price' => $lineData['unit_price'],
                                'sort_order' => $index,
                            ])->save();
                            $existingIds[] = $line->id;
                        }
                    } else {
                        // Create new line
                        $line = $invoice->lines()->create([
                            'type' => $lineData['type'],
                            'description' => $lineData['description'],
                            'details' => $lineData['details'] ?? null,
                            'quantity' => $lineData['quantity'],
                            'unit' => $lineData['unit'] ?? null,
                            'unit_price' => $lineData['unit_price'],
                            'taxable' => $lineData['type'] !== 'expense',
                            'sort_order' => $index,
                        ]);
                        $existingIds[] = $line->id;
                    }
                }
            }

            // Recalculate totals
            $invoice->recalculateTotals();

            // Sync changes to PayPal if linked
            if ($invoice->paypal_invoice_id) {
                try {
                    $invoice->load(['client', 'lines']);
                    $this->paypalService->updateInvoice($invoice);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to sync invoice update to PayPal', [
                        'invoice_id' => $invoice->id,
                        'paypal_invoice_id' => $invoice->paypal_invoice_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Regenerate PDF for sent invoices (clients may access it)
            // For drafts, just delete - it will regenerate when needed
            if ($invoice->status !== Invoice::STATUS_DRAFT) {
                $this->pdfGenerator->deletePdf($invoice);
                $this->pdfGenerator->generateAndStore($invoice);
            } else {
                $this->pdfGenerator->deletePdf($invoice);
            }

            // Log activity
            $invoice->activities()->create([
                'type' => 'updated',
                'description' => 'Invoice updated',
                'user_id' => request()->user()->id,
                'metadata' => array_filter([
                    'fields_changed' => array_keys($invoice->getChanges()),
                    'notify_client' => $shouldNotify,
                ]),
            ]);

            // Send update notification to client if requested
            if ($shouldNotify) {
                $this->sendUpdateNotification($invoice, $updateSummary);
            }

            $successMessage = $shouldNotify
                ? 'Invoice updated and client notified'
                : 'Invoice updated successfully';

            return redirect()->route('invoices.show', $invoice)
                ->with('success', $successMessage);
        });
    }

    /**
     * Send invoice update notification to client.
     */
    protected function sendUpdateNotification(Invoice $invoice, ?string $updateSummary = null): void
    {
        // Regenerate PDF before sending
        $this->pdfGenerator->generateAndStore($invoice);

        // Get payment link for email
        $paymentLink = $invoice->paypal_invoice_id && $invoice->amount_due > 0
            ? $this->paypalService->getPaymentLink($invoice)
            : null;

        // Get recipient email
        $recipientEmail = $invoice->client->billing_email
            ?? $invoice->client->contacts()->first()?->email;

        if ($recipientEmail) {
            \Mail::to($recipientEmail)->send(
                new \App\Mail\InvoiceUpdatedMail($invoice, $paymentLink, $updateSummary)
            );
        }
    }

    /**
     * Record a manual payment.
     */
    public function recordPayment(Request $request, Invoice $invoice)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|in:paypal,ach,check,wire,credit_card,other',
            'payment_date' => 'required|date',
            'reference' => 'nullable|string|max:255',
            'transaction_id' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $payment = $invoice->payments()->create([
            'amount' => $validated['amount'],
            'method' => $validated['method'],
            'payment_date' => Carbon::parse($validated['payment_date']),
            'reference' => $validated['reference'],
            'transaction_id' => $validated['transaction_id'],
            'notes' => $validated['notes'],
            'status' => Payment::STATUS_COMPLETED,
        ]);

        // Invoice totals auto-recalculate via Payment model observer

        $invoice->activities()->create([
            'type' => 'payment_recorded',
            'description' => 'Payment of $'.number_format($validated['amount'], 2).' recorded via '.$validated['method'],
            'user_id' => $request->user()->id,
            'metadata' => [
                'amount' => $validated['amount'],
                'method' => $validated['method'],
                'payment_id' => $payment->id,
            ],
        ]);

        return back()->with('success', 'Payment recorded successfully');
    }

    /**
     * Delete a draft invoice.
     */
    public function destroy(Invoice $invoice)
    {
        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            return back()->with('error', 'Only draft invoices can be deleted.');
        }

        $invoice->lines()->delete();
        $invoice->delete();

        return redirect()->route('invoices.index')->with('success', 'Draft invoice deleted.');
    }

    /**
     * Delete a payment.
     */
    public function deletePayment(Invoice $invoice, Payment $payment)
    {
        if ($payment->invoice_id !== $invoice->id) {
            abort(403);
        }

        $payment->delete();

        return back()->with('success', 'Payment deleted');
    }

    /**
     * Cancel an invoice.
     */
    public function cancel(Request $request, Invoice $invoice)
    {
        $reason = $request->input('reason');

        $this->invoiceService->cancel($invoice, $reason);

        return back()->with('success', 'Invoice cancelled');
    }

    /**
     * Clone an invoice.
     */
    public function duplicate(Invoice $invoice)
    {
        $newInvoice = $this->invoiceService->clone($invoice);

        return redirect()->route('invoices.edit', $newInvoice)
            ->with('success', 'Invoice duplicated as draft');
    }

    /**
     * API: Get unbilled time for a client.
     */
    public function getUnbilledTime(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'project_id' => 'nullable|exists:projects,id',
        ]);

        $query = TimeEntry::where('client_id', $validated['client_id'])
            ->where('is_billable', true)
            ->where('is_billed', false)
            ->whereNotNull('hourly_rate')
            ->where('hours', '>', 0)
            ->with(['project:id,name', 'task:id,title'])
            ->orderBy('spent_date');

        if (! empty($validated['project_id'])) {
            $query->where('project_id', $validated['project_id']);
        }

        $entries = $query->get()->map(fn ($e) => [
            'id' => $e->id,
            'date' => $e->spent_date->format('M j, Y'),
            'hours' => $e->hours,
            'rate' => $e->hourly_rate,
            'amount' => round($e->hours * $e->hourly_rate, 2),
            'notes' => $e->notes,
            'project' => $e->project?->name,
            'task' => $e->task?->title,
        ]);

        $totals = [
            'hours' => $entries->sum('hours'),
            'amount' => $entries->sum('amount'),
        ];

        return response()->json([
            'entries' => $entries,
            'totals' => $totals,
        ]);
    }

    /**
     * API: Get client's projects.
     */
    public function getClientProjects(Client $client)
    {
        $projects = $client->projects()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json($projects);
    }

    /**
     * Schedule an ad-hoc reminder for this invoice.
     */
    public function addReminder(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);

        $scheduledAt = Carbon::parse($data['scheduled_at']);
        $offset = $invoice->due_date->diffInDays($scheduledAt, false);

        $invoice->reminders()->create([
            'type' => InvoiceReminderSchedule::typeForOffset((int) $offset),
            'days_offset' => (int) $offset,
            'scheduled_at' => $scheduledAt,
            'status' => InvoiceReminder::STATUS_PENDING,
        ]);

        return back()->with('success', 'Reminder scheduled.');
    }

    /**
     * Reschedule an existing pending reminder.
     */
    public function updateReminder(Request $request, Invoice $invoice, InvoiceReminder $reminder)
    {
        abort_unless($reminder->invoice_id === $invoice->id, 404);
        abort_unless($reminder->isPending(), 422, 'Only pending reminders can be edited.');

        $data = $request->validate([
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);

        $scheduledAt = Carbon::parse($data['scheduled_at']);
        $offset = (int) $invoice->due_date->diffInDays($scheduledAt, false);

        $reminder->update([
            'scheduled_at' => $scheduledAt,
            'days_offset' => $offset,
            'type' => InvoiceReminderSchedule::typeForOffset($offset),
        ]);

        return back()->with('success', 'Reminder rescheduled.');
    }

    /**
     * Cancel a pending reminder (does not delete; status = cancelled for audit).
     */
    public function cancelReminder(Invoice $invoice, InvoiceReminder $reminder)
    {
        abort_unless($reminder->invoice_id === $invoice->id, 404);
        abort_unless($reminder->isPending(), 422, 'Only pending reminders can be cancelled.');

        $reminder->cancel();

        return back()->with('success', 'Reminder cancelled.');
    }

    /**
     * Toggle the master "no reminders for this invoice" flag.
     */
    public function toggleRemindersDisabled(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'reminders_disabled' => ['required', 'boolean'],
        ]);

        $invoice->update(['reminders_disabled' => $data['reminders_disabled']]);

        if ($data['reminders_disabled']) {
            $invoice->reminders()
                ->where('status', InvoiceReminder::STATUS_PENDING)
                ->update(['status' => InvoiceReminder::STATUS_CANCELLED]);
        } else {
            InvoiceReminder::scheduleForInvoice($invoice);
        }

        return back()->with('success', $data['reminders_disabled']
            ? 'Reminders disabled for this invoice.'
            : 'Reminders re-enabled for this invoice.');
    }
}
