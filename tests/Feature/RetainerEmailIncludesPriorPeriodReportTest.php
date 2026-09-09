<?php

use App\Mail\InvoiceSentMail;
use App\Mail\RetainerReportMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\RetainerPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.client_email_cc' => 'owner@example.com']);
});

it('CCs internal stakeholders on InvoiceSentMail', function () {
    $client = Client::factory()->create(['name' => 'Example Client LLC']);
    $invoice = Invoice::factory()->create(['client_id' => $client->id]);

    $mail = new InvoiceSentMail($invoice);
    $envelope = $mail->envelope();

    expect($envelope->cc)->toHaveCount(1);
    expect($envelope->cc[0]->address)->toBe('owner@example.com');
});

it('CCs internal stakeholders on RetainerReportMail', function () {
    $client = Client::factory()->create();
    $period = RetainerPeriod::factory()->create(['client_id' => $client->id]);

    $mail = new RetainerReportMail($period);
    $envelope = $mail->envelope();

    expect($envelope->cc)->toHaveCount(1);
    expect($envelope->cc[0]->address)->toBe('owner@example.com');
});

it('CCs internal stakeholders on the full set of client-facing mailables', function () {
    $client = Client::factory()->create();

    // InvoiceReminderMail
    $invoice = Invoice::factory()->create(['client_id' => $client->id]);
    $reminder = new App\Models\InvoiceReminder([
        'invoice_id' => $invoice->id,
        'type' => App\Models\InvoiceReminder::TYPE_BEFORE_DUE,
        'days_offset' => 3,
    ]);
    expect((new App\Mail\InvoiceReminderMail($invoice, $reminder))->envelope()->cc)->toHaveCount(1);

    // InvoiceUpdatedMail
    expect((new App\Mail\InvoiceUpdatedMail($invoice))->envelope()->cc)->toHaveCount(1);
});

it('supports multiple comma-separated CC addresses', function () {
    config(['app.client_email_cc' => 'owner@example.com, ops@example.com']);

    $period = RetainerPeriod::factory()->create();
    $mail = new RetainerReportMail($period);

    expect($mail->envelope()->cc)->toHaveCount(2);
});

it('skips invalid email entries in the CC config', function () {
    config(['app.client_email_cc' => 'owner@example.com, not-an-email, ops@example.com']);

    $period = RetainerPeriod::factory()->create();
    $mail = new RetainerReportMail($period);

    expect($mail->envelope()->cc)->toHaveCount(2);
});

it('handles an empty CC config gracefully', function () {
    config(['app.client_email_cc' => '']);

    $period = RetainerPeriod::factory()->create();
    $mail = new RetainerReportMail($period);

    expect($mail->envelope()->cc)->toBe([]);
});

it('invoice email links to the most recent completed retainer period, not the invoice own period', function () {
    $client = Client::factory()->create();

    // Prior month — already delivered.
    $priorPeriod = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
        'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
    ]);

    // Current month — in-progress, the one this invoice is for.
    $currentPeriod = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
    ]);

    $invoice = Invoice::factory()->create([
        'client_id' => $client->id,
        'retainer_period_id' => $currentPeriod->id,
    ]);

    $mail = new InvoiceSentMail($invoice);
    $reflection = new ReflectionMethod($mail, 'resolveReportPeriod');
    $reflection->setAccessible(true);
    $resolved = $reflection->invoke($mail);

    expect($resolved->id)->toBe($priorPeriod->id);
});

it('falls back to invoice own period when no completed prior period exists', function () {
    $client = Client::factory()->create();

    $currentPeriod = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
    ]);

    $invoice = Invoice::factory()->create([
        'client_id' => $client->id,
        'retainer_period_id' => $currentPeriod->id,
    ]);

    $mail = new InvoiceSentMail($invoice);
    $reflection = new ReflectionMethod($mail, 'resolveReportPeriod');
    $reflection->setAccessible(true);
    $resolved = $reflection->invoke($mail);

    expect($resolved->id)->toBe($currentPeriod->id);
});
