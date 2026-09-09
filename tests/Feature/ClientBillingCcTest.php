<?php

use App\Jobs\GenerateRecurringInvoicesJob;
use App\Jobs\SendInvoiceRemindersJob;
use App\Mail\InvoiceReminderMail;
use App\Mail\InvoiceSentMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\User;
use App\Services\Invoicing\InvoiceService;
use App\Services\PayPal\PayPalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('parses billing cc emails from comma, semicolon, and newline separated input', function () {
    $client = Client::factory()->create([
        'billing_cc_emails' => "ap@example.com, finance@example.com;ops@example.com\ncontroller@example.com",
    ]);

    expect($client->billingCcList())->toBe([
        'ap@example.com',
        'finance@example.com',
        'ops@example.com',
        'controller@example.com',
    ]);
});

it('drops invalid and duplicate billing cc entries', function () {
    $client = Client::factory()->create([
        'billing_cc_emails' => 'ap@example.com, not-an-email, , ap@example.com',
    ]);

    expect($client->billingCcList())->toBe(['ap@example.com']);
});

it('returns an empty cc list when billing_cc_emails is null', function () {
    $client = Client::factory()->create(['billing_cc_emails' => null]);

    expect($client->billingCcList())->toBe([]);
});

it('persists billing_email and billing_cc_emails through the client update form', function () {
    $client = Client::factory()->create([
        'billing_email' => null,
        'billing_cc_emails' => null,
    ]);

    $response = $this->put("/clients/{$client->id}", [
        'name' => $client->name,
        'status' => 'active',
        'billing_email' => 'billing@acme.com',
        'billing_cc_emails' => 'ap@acme.com, finance@acme.com',
    ]);

    $response->assertRedirect();

    $client->refresh();
    expect($client->billing_email)->toBe('billing@acme.com');
    expect($client->billing_cc_emails)->toBe('ap@acme.com, finance@acme.com');
});

it('rejects an invalid billing_email on client update', function () {
    $client = Client::factory()->create();

    $response = $this->put("/clients/{$client->id}", [
        'name' => $client->name,
        'billing_email' => 'not-an-email',
    ]);

    $response->assertSessionHasErrors('billing_email');
});

it('CCs the billing cc list when auto-sending a recurring invoice', function () {
    Mail::fake();

    $client = Client::factory()->create([
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 3000,
        'recurring_invoice_day' => now()->day,
        'recurring_invoice_auto_send' => true,
        'billing_email' => 'ceo@example.com',
        'billing_cc_emails' => 'ap@example.com; finance@example.com',
    ]);

    $paypalService = $this->mock(PayPalService::class);
    $paypalService->shouldReceive('createInvoice');

    (new GenerateRecurringInvoicesJob)->handle(app(InvoiceService::class), $paypalService);

    Mail::assertSent(InvoiceSentMail::class, function (InvoiceSentMail $mail) {
        return $mail->hasTo('ceo@example.com')
            && $mail->hasCc('ap@example.com')
            && $mail->hasCc('finance@example.com');
    });
});

it('auto-sends without CC when the client has no billing cc list', function () {
    Mail::fake();

    $client = Client::factory()->create([
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 3000,
        'recurring_invoice_day' => now()->day,
        'recurring_invoice_auto_send' => true,
        'billing_email' => 'ceo@example.com',
        'billing_cc_emails' => null,
    ]);

    $paypalService = $this->mock(PayPalService::class);
    $paypalService->shouldReceive('createInvoice');

    (new GenerateRecurringInvoicesJob)->handle(app(InvoiceService::class), $paypalService);

    Mail::assertSent(InvoiceSentMail::class, function (InvoiceSentMail $mail) {
        return $mail->hasTo('ceo@example.com') && $mail->cc === [];
    });
});

it('CCs the billing cc list on invoice reminder emails', function () {
    Mail::fake();

    $client = Client::factory()->create([
        'billing_email' => 'ceo@example.com',
        'billing_cc_emails' => 'ap@example.com',
    ]);
    $invoice = Invoice::factory()->sent()->create(['client_id' => $client->id]);

    InvoiceReminder::create([
        'invoice_id' => $invoice->id,
        'type' => 'due_soon',
        'days_offset' => 0,
        'scheduled_at' => now()->subHour(),
        'status' => InvoiceReminder::STATUS_PENDING,
    ]);

    $paypalService = $this->mock(PayPalService::class);

    (new SendInvoiceRemindersJob)->handle($paypalService);

    Mail::assertSent(InvoiceReminderMail::class, function (InvoiceReminderMail $mail) {
        return $mail->hasTo('ceo@example.com') && $mail->hasCc('ap@example.com');
    });
});
