<?php

use App\Mail\RfpProposalMail;
use App\Models\RfpOpportunity;
use App\Models\RfpProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->user);
    Storage::fake();
});

test('download proposal pdf route returns pdf', function () {
    $rfp = RfpOpportunity::factory()->create();
    $proposal = RfpProposal::factory()->create([
        'rfp_opportunity_id' => $rfp->id,
        'full_content' => 'Test proposal content',
    ]);

    // Pre-create a fake PDF file since TailwindPdf needs Chrome
    Storage::put("rfp-proposals/{$rfp->id}/{$proposal->id}.pdf", 'fake-pdf-content');

    $response = $this->get("/rfp/{$rfp->id}/proposals/{$proposal->id}/pdf/download");

    $response->assertOk();
    $response->assertDownload();
});

test('preview proposal pdf route returns inline pdf', function () {
    $rfp = RfpOpportunity::factory()->create();
    $proposal = RfpProposal::factory()->create([
        'rfp_opportunity_id' => $rfp->id,
    ]);

    Storage::put("rfp-proposals/{$rfp->id}/{$proposal->id}.pdf", 'fake-pdf-content');

    $response = $this->get("/rfp/{$rfp->id}/proposals/{$proposal->id}/pdf");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
});

test('send proposal email validates required fields', function () {
    $rfp = RfpOpportunity::factory()->create();
    $proposal = RfpProposal::factory()->create([
        'rfp_opportunity_id' => $rfp->id,
    ]);

    $response = $this->post("/rfp/{$rfp->id}/proposals/{$proposal->id}/send-email", []);

    $response->assertSessionHasErrors(['to_email', 'subject', 'body']);
});

test('send proposal email validates email format', function () {
    $rfp = RfpOpportunity::factory()->create();
    $proposal = RfpProposal::factory()->create([
        'rfp_opportunity_id' => $rfp->id,
    ]);

    $response = $this->post("/rfp/{$rfp->id}/proposals/{$proposal->id}/send-email", [
        'to_email' => 'not-an-email',
        'subject' => 'Test Subject',
        'body' => 'Test body content',
    ]);

    $response->assertSessionHasErrors(['to_email']);
});

test('send proposal email sends mail and updates proposal status', function () {
    Mail::fake();

    $rfp = RfpOpportunity::factory()->create([
        'status' => 'pursuing',
        'contact_name' => 'Jane Smith',
        'contact_email' => 'jane@example.com',
    ]);
    $proposal = RfpProposal::factory()->create([
        'rfp_opportunity_id' => $rfp->id,
        'status' => 'approved',
    ]);

    // Pre-create the PDF
    Storage::put("rfp-proposals/{$rfp->id}/{$proposal->id}.pdf", 'fake-pdf-content');

    $response = $this->post("/rfp/{$rfp->id}/proposals/{$proposal->id}/send-email", [
        'to_email' => 'jane@example.com',
        'to_name' => 'Jane Smith',
        'subject' => 'Proposal: Test RFP',
        'body' => 'Please find our proposal attached.',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    Mail::assertSent(RfpProposalMail::class, function ($mail) {
        return $mail->hasTo('jane@example.com');
    });

    // Proposal should be marked as submitted
    expect($proposal->fresh()->status)->toBe('submitted')
        ->and($proposal->fresh()->submitted_at)->not->toBeNull()
        ->and($proposal->fresh()->submitted_via)->toBe('email');

    // RFP should be updated to submitted
    expect($rfp->fresh()->status)->toBe('submitted');
});

test('send proposal email does not regress opportunity status', function () {
    Mail::fake();

    $rfp = RfpOpportunity::factory()->create([
        'status' => 'submitted', // Already submitted
    ]);
    $proposal = RfpProposal::factory()->create([
        'rfp_opportunity_id' => $rfp->id,
        'status' => 'draft',
        'version' => 2,
    ]);

    Storage::put("rfp-proposals/{$rfp->id}/{$proposal->id}.pdf", 'fake-pdf-content');

    $this->post("/rfp/{$rfp->id}/proposals/{$proposal->id}/send-email", [
        'to_email' => 'contact@example.com',
        'subject' => 'Updated Proposal',
        'body' => 'Please find our updated proposal.',
    ]);

    // Should stay submitted, not regress
    expect($rfp->fresh()->status)->toBe('submitted');
});
