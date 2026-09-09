<?php

use App\Jobs\GenerateRecurringInvoicesJob;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\Invoicing\InvoiceService;
use App\Services\PayPal\PayPalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('processes merge tags in recurring invoice description', function () {
    $client = Client::factory()->create([
        'name' => 'Acme Corp',
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 5000,
        'recurring_invoice_day' => now()->day,
        'recurring_invoice_auto_send' => false,
        'recurring_invoice_description' => 'Retainer for {client_name} - {month_year}',
    ]);

    $paypalService = $this->mock(PayPalService::class);

    (new GenerateRecurringInvoicesJob)->handle(app(InvoiceService::class), $paypalService);

    $invoice = Invoice::where('client_id', $client->id)->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->subject)->toContain('Acme Corp');
    expect($invoice->subject)->toContain(now()->startOfMonth()->format('F Y'));

    $line = InvoiceLine::where('invoice_id', $invoice->id)->first();
    expect($line->description)->toContain('Acme Corp');
});

it('labels the invoice for the next month when the client bills in advance', function () {
    $client = Client::factory()->create([
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 2000,
        'recurring_invoice_day' => now()->day,
        'recurring_invoice_auto_send' => false,
        'recurring_invoice_in_advance' => true,
        'recurring_invoice_description' => 'Monthly retainer for {month_year}',
    ]);

    $paypalService = $this->mock(PayPalService::class);

    (new GenerateRecurringInvoicesJob)->handle(app(InvoiceService::class), $paypalService);

    $invoice = Invoice::where('client_id', $client->id)->firstOrFail();
    $nextMonth = now()->addMonthNoOverflow()->startOfMonth()->format('F Y');

    expect($invoice->subject)->toBe("Monthly retainer for {$nextMonth}");

    $line = InvoiceLine::where('invoice_id', $invoice->id)->firstOrFail();
    expect($line->description)->toContain($nextMonth);
});

it('labels the invoice for the current month when not billing in advance', function () {
    $client = Client::factory()->create([
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 2000,
        'recurring_invoice_day' => now()->day,
        'recurring_invoice_auto_send' => false,
        'recurring_invoice_in_advance' => false,
        'recurring_invoice_description' => 'Monthly retainer for {month_year}',
    ]);

    $paypalService = $this->mock(PayPalService::class);

    (new GenerateRecurringInvoicesJob)->handle(app(InvoiceService::class), $paypalService);

    $invoice = Invoice::where('client_id', $client->id)->firstOrFail();
    $thisMonth = now()->startOfMonth()->format('F Y');

    expect($invoice->subject)->toBe("Monthly retainer for {$thisMonth}");
});

it('no longer registers the stubbed invoices:generate-recurring command', function () {
    $commands = array_keys(\Illuminate\Support\Facades\Artisan::all());

    expect($commands)->not->toContain('invoices:generate-recurring');
});

it('schedules exactly one recurring invoice generator', function () {
    $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);

    $generators = collect($schedule->events())
        ->filter(fn ($event) => $event->getSummaryForDisplay() === 'generate-recurring-invoices');

    expect($generators)->toHaveCount(1);
});

it('sets is_recurring flag on generated invoices', function () {
    $client = Client::factory()->create([
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 3000,
        'recurring_invoice_day' => now()->day,
        'recurring_invoice_auto_send' => false,
    ]);

    $paypalService = $this->mock(PayPalService::class);

    (new GenerateRecurringInvoicesJob)->handle(app(InvoiceService::class), $paypalService);

    $invoice = Invoice::where('client_id', $client->id)->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->is_recurring)->toBeTrue();
});

it('uses default template when no description set', function () {
    $client = Client::factory()->create([
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 2000,
        'recurring_invoice_day' => now()->day,
        'recurring_invoice_auto_send' => false,
        'recurring_invoice_description' => null,
    ]);

    $paypalService = $this->mock(PayPalService::class);

    (new GenerateRecurringInvoicesJob)->handle(app(InvoiceService::class), $paypalService);

    $invoice = Invoice::where('client_id', $client->id)->first();
    expect($invoice->subject)->toBe('Monthly Retainer - '.now()->startOfMonth()->format('F Y'));
});

it('skips clients already generated this month', function () {
    $client = Client::factory()->create([
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 1000,
        'recurring_invoice_day' => now()->day,
        'recurring_invoice_auto_send' => false,
        'recurring_invoice_last_generated' => now()->startOfMonth(),
    ]);

    $paypalService = $this->mock(PayPalService::class);

    (new GenerateRecurringInvoicesJob)->handle(app(InvoiceService::class), $paypalService);

    expect(Invoice::where('client_id', $client->id)->count())->toBe(0);
});

it('shows recurring clients on invoice index', function () {
    $client = Client::factory()->create([
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 5000,
        'recurring_invoice_day' => 15,
        'recurring_invoice_auto_send' => true,
        'payment_terms' => 'Net 30',
    ]);

    $response = $this->get('/invoices');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('Invoices/Index')
        ->has('recurringClients', 1)
        ->where('recurringClients.0.client_name', $client->name)
        ->where('recurringClients.0.amount', fn ($amount) => (int) $amount === 5000)
        ->where('recurringClients.0.auto_send', true)
    );
});

it('uses client payment terms on generated invoice', function () {
    $client = Client::factory()->create([
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 4000,
        'recurring_invoice_day' => now()->day,
        'recurring_invoice_auto_send' => false,
        'payment_terms' => 'Net 45',
    ]);

    $paypalService = $this->mock(PayPalService::class);

    (new GenerateRecurringInvoicesJob)->handle(app(InvoiceService::class), $paypalService);

    $invoice = Invoice::where('client_id', $client->id)->first();
    expect($invoice->payment_terms)->toBe('Net 45');
});

it('can delete a draft invoice', function () {
    $invoice = Invoice::factory()->create(['status' => Invoice::STATUS_DRAFT]);

    $response = $this->delete("/invoices/{$invoice->id}");

    $response->assertRedirect(route('invoices.index'));
    expect(Invoice::withTrashed()->find($invoice->id)->trashed())->toBeTrue();
});

it('cannot delete a sent invoice', function () {
    $invoice = Invoice::factory()->sent()->create();

    $response = $this->delete("/invoices/{$invoice->id}");

    $response->assertRedirect();
    expect(Invoice::find($invoice->id))->not->toBeNull();
});

it('skips invoice creation when auto-send is on but client has no recipient', function () {
    $client = Client::factory()->create([
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 3000,
        'recurring_invoice_day' => now()->day,
        'recurring_invoice_auto_send' => true,
        'billing_email' => null,
    ]);
    // No contacts created, no billing_email.

    $paypalService = $this->mock(PayPalService::class);

    (new GenerateRecurringInvoicesJob)->handle(app(InvoiceService::class), $paypalService);

    expect(Invoice::where('client_id', $client->id)->count())->toBe(0);
    expect($client->fresh()->recurring_invoice_last_generated)->toBeNull();
});

it('still generates the invoice when auto-send is off even without a recipient', function () {
    $client = Client::factory()->create([
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 3000,
        'recurring_invoice_day' => now()->day,
        'recurring_invoice_auto_send' => false,
        'billing_email' => null,
    ]);

    $paypalService = $this->mock(PayPalService::class);

    (new GenerateRecurringInvoicesJob)->handle(app(InvoiceService::class), $paypalService);

    expect(Invoice::where('client_id', $client->id)->count())->toBe(1);
});

it('renders the retainer report Blade view without errors', function () {
    $client = Client::factory()->create(['name' => 'Acme Co']);
    $period = \App\Models\RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'hours_included' => 20,
        'period_start' => now()->subMonth()->startOfMonth(),
        'period_end' => now()->subMonth()->endOfMonth(),
    ]);

    $generator = app(\App\Services\Reports\RetainerReportPdfGenerator::class);
    $html = $generator->renderHtml($period);

    expect($html)->toContain('Acme Co');
    expect($html)->toContain('Retainer Report');
    expect($html)->toContain('Total hours');
});

it('public CSV download honors signed URL and rejects unsigned access', function () {
    \Illuminate\Support\Facades\Mail::fake();
    $client = \App\Models\Client::factory()->create();
    $period = \App\Models\RetainerPeriod::factory()->create(['client_id' => $client->id]);
    \App\Models\TimeEntry::factory()->create([
        'client_id' => $client->id,
        'hours' => 1.0,
        'spent_date' => $period->period_start,
        'notes' => 'public csv test row',
        'source' => 'manual',
    ]);
    $this->mock(\App\Services\Reports\RetainerNarrativeService::class, function ($m) {
        $m->shouldReceive('getCached')->andReturn(null);
        $m->shouldReceive('buildNarrative')->andReturn([
            'topics' => [], 'value_summary' => '', 'total_estimated_hours' => 0,
            'generated_at' => now()->toIso8601String(), 'warnings' => [],
        ]);
    });

    $this->get("/r/retainer-reports/{$period->id}/csv")->assertForbidden();

    $signed = \Illuminate\Support\Facades\URL::signedRoute(
        'retainer-reports.public.csv',
        ['period' => $period->id],
        now()->addDays(7),
    );
    $response = $this->get($signed);
    $response->assertOk();
    expect($response->streamedContent())->toContain('public csv test row');
});

it('rejects unsigned access to the public retainer report URL', function () {
    $period = \App\Models\RetainerPeriod::factory()->create();

    $this->get("/r/retainer-reports/{$period->id}")->assertForbidden();
});

it('serves the retainer report when accessed via a valid signed URL', function () {
    $period = \App\Models\RetainerPeriod::factory()->create();

    $url = \App\Http\Controllers\RetainerReportController::signedUrlFor($period);

    $this->get($url)->assertOk();
});

it('holds the invoice for review when retainer hours are below 80% of the budget', function () {
    $client = Client::factory()->create([
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 3000,
        'recurring_invoice_day' => now()->day,
        'recurring_invoice_auto_send' => true,
        'billing_email' => 'ceo@example.com',
    ]);

    // Period that ended yesterday (matches an invoice issued today)
    \App\Models\RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'hours_included' => 20,
        'period_start' => now()->subMonth()->startOfMonth(),
        'period_end' => now()->subDay()->startOfDay(),
    ]);

    // No time entries / no meetings / no agent runs → 0 hours used, well below 80%
    $paypalService = $this->mock(PayPalService::class);
    $paypalService->shouldNotReceive('createInvoice');

    (new \App\Jobs\GenerateRecurringInvoicesJob)->handle(app(InvoiceService::class), $paypalService);

    $invoice = Invoice::where('client_id', $client->id)->firstOrFail();
    expect($invoice->status)->toBe(Invoice::STATUS_DRAFT);
    expect($invoice->pending_review)->toBeTrue();
    expect($invoice->retainer_period_id)->not->toBeNull();
    expect($invoice->pending_review_reason)->toContain('Low hours');

    $activity = \App\Models\InvoiceActivity::where('invoice_id', $invoice->id)
        ->where('type', 'held_for_review')
        ->first();
    expect($activity)->not->toBeNull();
    expect($activity->metadata)->toHaveKey('prior_periods');
});

it('does not hold for review when there is no retainer period for the client', function () {
    $client = Client::factory()->create([
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 3000,
        'recurring_invoice_day' => now()->day,
        'recurring_invoice_auto_send' => false,
        'billing_email' => 'ceo@example.com',
    ]);

    $paypalService = $this->mock(PayPalService::class);

    (new \App\Jobs\GenerateRecurringInvoicesJob)->handle(app(InvoiceService::class), $paypalService);

    $invoice = Invoice::where('client_id', $client->id)->firstOrFail();
    expect($invoice->pending_review)->toBeFalse();
    expect($invoice->retainer_period_id)->toBeNull();
});

it('clears pending_review when an invoice is marked as sent', function () {
    $invoice = Invoice::factory()->create([
        'pending_review' => true,
        'pending_review_reason' => 'Low hours this period',
    ]);

    app(InvoiceService::class)->markAsSent($invoice);

    expect($invoice->fresh()->pending_review)->toBeFalse();
    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_SENT);
});

it('records an auto_send_failed activity when PayPal throws during auto-send', function () {
    $client = Client::factory()->create([
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 3000,
        'recurring_invoice_day' => now()->day,
        'recurring_invoice_auto_send' => true,
        'billing_email' => 'ceo@example.com',
    ]);

    $paypalService = $this->mock(PayPalService::class);
    $paypalService->shouldReceive('createInvoice')->andThrow(new \RuntimeException('PayPal exploded'));

    (new GenerateRecurringInvoicesJob)->handle(app(InvoiceService::class), $paypalService);

    $invoice = Invoice::where('client_id', $client->id)->firstOrFail();
    expect($invoice->status)->toBe(Invoice::STATUS_DRAFT);

    $activity = \App\Models\InvoiceActivity::where('invoice_id', $invoice->id)
        ->where('type', 'auto_send_failed')
        ->first();
    expect($activity)->not->toBeNull();
    expect($activity->description)->toContain('PayPal exploded');
    expect($activity->metadata['error'])->toBe('PayPal exploded');
});
