<?php

use App\Models\OutreachCampaign;
use App\Models\OutreachMessage;
use App\Models\OutreachSequence;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new OutreachMessage)->getGuarded())->toBe([]);
});

test('casts personalization_context to array', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);

    $message = OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Test',
        'body' => 'Test body',
        'personalization_context' => ['name' => 'John'],
        'status' => OutreachMessage::STATUS_DRAFT,
    ]);

    expect($message->personalization_context)->toBeArray()
        ->and($message->personalization_context)->toBe(['name' => 'John']);
});

test('casts scheduled_for to datetime', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);

    $message = OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Test',
        'body' => 'Test body',
        'scheduled_for' => now(),
        'status' => OutreachMessage::STATUS_DRAFT,
    ]);

    expect($message->scheduled_for)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts sent_at to datetime', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);

    $message = OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Test',
        'body' => 'Test body',
        'sent_at' => now(),
        'status' => OutreachMessage::STATUS_DRAFT,
    ]);

    expect($message->sent_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts opened_at to datetime', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);

    $message = OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Test',
        'body' => 'Test body',
        'opened_at' => now(),
        'status' => OutreachMessage::STATUS_DRAFT,
    ]);

    expect($message->opened_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts replied_at to datetime', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);

    $message = OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Test',
        'body' => 'Test body',
        'replied_at' => now(),
        'status' => OutreachMessage::STATUS_DRAFT,
    ]);

    expect($message->replied_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts approved_at to datetime', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);

    $message = OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Test',
        'body' => 'Test body',
        'approved_at' => now(),
        'status' => OutreachMessage::STATUS_DRAFT,
    ]);

    expect($message->approved_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to sequence relationship', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);

    $message = OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Test',
        'body' => 'Test body',
        'status' => OutreachMessage::STATUS_DRAFT,
    ]);

    expect($message->sequence())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to prospect relationship', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);

    $message = OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Test',
        'body' => 'Test body',
        'status' => OutreachMessage::STATUS_DRAFT,
    ]);

    expect($message->prospect())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to lead relationship', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);

    $message = OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Test',
        'body' => 'Test body',
        'status' => OutreachMessage::STATUS_DRAFT,
    ]);

    expect($message->lead())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to created by run relationship', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);

    $message = OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Test',
        'body' => 'Test body',
        'status' => OutreachMessage::STATUS_DRAFT,
    ]);

    expect($message->createdByRun())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to approved by relationship', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);

    $message = OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Test',
        'body' => 'Test body',
        'status' => OutreachMessage::STATUS_DRAFT,
    ]);

    expect($message->approvedBy())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('approve method sets status and user', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);
    $user = User::factory()->create();

    $message = OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Test',
        'body' => 'Test body',
        'status' => OutreachMessage::STATUS_DRAFT,
    ]);

    $message->approve($user);

    expect($message->fresh()->status)->toBe(OutreachMessage::STATUS_APPROVED)
        ->and($message->fresh()->approved_by)->toBe($user->id)
        ->and($message->fresh()->approved_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('markSent method sets status and timestamp', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);

    $message = OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Test',
        'body' => 'Test body',
        'status' => OutreachMessage::STATUS_APPROVED,
    ]);

    $message->markSent();

    expect($message->fresh()->status)->toBe(OutreachMessage::STATUS_SENT)
        ->and($message->fresh()->sent_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('markOpened method sets status and timestamp', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);

    $message = OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Test',
        'body' => 'Test body',
        'status' => OutreachMessage::STATUS_SENT,
    ]);

    $message->markOpened();

    expect($message->fresh()->status)->toBe(OutreachMessage::STATUS_OPENED)
        ->and($message->fresh()->opened_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('markOpened method does not update if already opened', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);
    $firstOpenTime = now()->subHours(2);

    $message = OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Test',
        'body' => 'Test body',
        'status' => OutreachMessage::STATUS_OPENED,
        'opened_at' => $firstOpenTime,
    ]);

    $message->markOpened();

    expect($message->fresh()->opened_at->toDateTimeString())->toBe($firstOpenTime->toDateTimeString());
});

test('recordReply method sets reply data', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);

    $message = OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Test',
        'body' => 'Test body',
        'status' => OutreachMessage::STATUS_SENT,
    ]);

    $replyText = 'Thanks for reaching out!';
    $message->recordReply($replyText, OutreachMessage::SENTIMENT_POSITIVE);

    expect($message->fresh()->status)->toBe(OutreachMessage::STATUS_REPLIED)
        ->and($message->fresh()->reply_text)->toBe($replyText)
        ->and($message->fresh()->reply_sentiment)->toBe(OutreachMessage::SENTIMENT_POSITIVE)
        ->and($message->fresh()->replied_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('readyToSend scope returns approved messages scheduled for now', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);

    OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Ready',
        'body' => 'Test body',
        'status' => OutreachMessage::STATUS_APPROVED,
        'scheduled_for' => now()->subMinutes(5),
    ]);

    OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Future',
        'body' => 'Test body',
        'status' => OutreachMessage::STATUS_APPROVED,
        'scheduled_for' => now()->addDays(1),
    ]);

    $results = OutreachMessage::readyToSend()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->subject)->toBe('Ready');
});

test('pendingApproval scope returns draft messages', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);

    OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Draft',
        'body' => 'Test body',
        'status' => OutreachMessage::STATUS_DRAFT,
    ]);

    OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Approved',
        'body' => 'Test body',
        'status' => OutreachMessage::STATUS_APPROVED,
    ]);

    $results = OutreachMessage::pendingApproval()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->subject)->toBe('Draft');
});

test('ofChannel scope filters by channel', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);

    OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Email message',
        'body' => 'Test body',
        'status' => OutreachMessage::STATUS_DRAFT,
    ]);

    OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'linkedin',
        'subject' => 'LinkedIn message',
        'body' => 'Test body',
        'status' => OutreachMessage::STATUS_DRAFT,
    ]);

    $results = OutreachMessage::ofChannel('email')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->channel)->toBe('email');
});

test('recipient accessor returns email for email channel', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create([
        'company_name' => 'Test',
        'contact_email' => 'test@example.com',
        'status' => 'new',
    ]);

    $message = OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Test',
        'body' => 'Test body',
        'status' => OutreachMessage::STATUS_DRAFT,
    ]);

    expect($message->recipient)->toBe('test@example.com');
});

test('can be created directly', function () {
    $sequence = createTestSequence();
    $prospect = Prospect::create(['company_name' => 'Test', 'status' => 'new']);

    $message = OutreachMessage::create([
        'sequence_id' => $sequence->id,
        'prospect_id' => $prospect->id,
        'channel' => 'email',
        'subject' => 'Test',
        'body' => 'Test body',
        'status' => OutreachMessage::STATUS_DRAFT,
    ]);

    expect($message)->toBeInstanceOf(OutreachMessage::class)
        ->and($message->exists)->toBeTrue();
});

// Helper function
function createTestSequence(): OutreachSequence
{
    $campaign = OutreachCampaign::create([
        'name' => 'Test Campaign',
        'type' => 'cold_outreach',
        'status' => 'active',
    ]);

    return OutreachSequence::create([
        'campaign_id' => $campaign->id,
        'step_number' => 1,
        'channel' => 'email',
        'subject_template' => 'Test Subject',
        'body_template' => 'Test Body',
        'delay_days' => 0,
        'is_active' => true,
    ]);
}
