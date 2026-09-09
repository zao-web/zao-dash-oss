<?php

use App\Events\NotificationCreated;
use App\Models\ApprovalRequest;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('observer creates notification when approval request is created', function () {
    $approval = ApprovalRequest::factory()->create();

    expect(Notification::count())->toBe(1);

    $notification = Notification::first();
    expect($notification->type)->toBe('approval_needed');
    expect($notification->title)->toBe('Approval Required');
    expect($notification->message)->toBe($approval->description);
    expect($notification->icon)->toBe('⚠️');
    expect($notification->metadata['approval_id'])->toBe($approval->id);
});

test('observer broadcasts event when approval is created', function () {
    Event::fake([NotificationCreated::class]);

    ApprovalRequest::factory()->create();

    Event::assertDispatched(NotificationCreated::class, function ($event) {
        return $event->notification->type === 'approval_needed';
    });
});

test('observer sets severity to error for high risk approvals', function () {
    $highRisk = ApprovalRequest::factory()->create(['risk_level' => 'high']);
    $notification = Notification::first();
    expect($notification->severity)->toBe('error');
});

test('observer sets severity to warning for medium risk approvals', function () {
    $mediumRisk = ApprovalRequest::factory()->create(['risk_level' => 'medium']);
    $notification = Notification::first();
    expect($notification->severity)->toBe('warning');
});

test('observer sets severity to info for low risk approvals', function () {
    $lowRisk = ApprovalRequest::factory()->create(['risk_level' => 'low']);
    $notification = Notification::first();
    expect($notification->severity)->toBe('info');
});

test('observer creates notification when approval is approved', function () {
    $user = User::factory()->create();
    $approval = ApprovalRequest::factory()->create(['status' => 'pending']);

    // Clear the created notification
    Notification::truncate();

    $approval->update([
        'status' => 'approved',
        'decided_by' => $user->id,
    ]);

    expect(Notification::count())->toBe(1);

    $notification = Notification::first();
    expect($notification->type)->toBe('approval_decided');
    expect($notification->title)->toBe('Approval Granted');
    expect($notification->icon)->toBe('✅');
    expect($notification->severity)->toBe('success');
    expect($notification->metadata['approval_id'])->toBe($approval->id);
});

test('observer creates notification when approval is rejected', function () {
    $user = User::factory()->create();
    $approval = ApprovalRequest::factory()->create(['status' => 'pending']);

    // Clear the created notification
    Notification::truncate();

    $approval->update([
        'status' => 'rejected',
        'decided_by' => $user->id,
    ]);

    expect(Notification::count())->toBe(1);

    $notification = Notification::first();
    expect($notification->type)->toBe('approval_decided');
    expect($notification->title)->toBe('Approval Rejected');
    expect($notification->icon)->toBe('❌');
    expect($notification->severity)->toBe('warning');
});

test('observer does not create notification for non-decision updates', function () {
    $approval = ApprovalRequest::factory()->create(['status' => 'pending']);

    // Clear the created notification
    Notification::truncate();

    // Update fields other than status
    $approval->update(['decision_note' => 'Some notes']);

    expect(Notification::count())->toBe(0);
});

test('observer only creates decision notification on status change', function () {
    $user = User::factory()->create();
    $approval = ApprovalRequest::factory()->create(['status' => 'pending']);

    // Clear the created notification
    Notification::truncate();

    $approval->update([
        'status' => 'approved',
        'decided_by' => $user->id,
    ]);

    expect(Notification::count())->toBe(1);

    // Update again with same status
    $approval->update(['decision_note' => 'Additional notes']);

    // Should still only have 1 notification
    expect(Notification::count())->toBe(1);
});

test('observer includes approval metadata in decision notification', function () {
    $user = User::factory()->create();
    $approval = ApprovalRequest::factory()->create([
        'status' => 'pending',
        'action_type' => 'send_email',
    ]);

    Notification::truncate();

    $approval->update([
        'status' => 'approved',
        'decided_by' => $user->id,
    ]);

    $notification = Notification::first();
    expect($notification->metadata)->toHaveKeys(['approval_id', 'action_type', 'decided_by']);
    expect($notification->metadata['action_type'])->toBe('send_email');
    expect($notification->metadata['decided_by'])->toBe($user->id);
});

test('observer notification message includes action type', function () {
    $user = User::factory()->create();
    $approval = ApprovalRequest::factory()->create([
        'status' => 'pending',
        'action_type' => 'publish_content',
    ]);

    Notification::truncate();

    $approval->update([
        'status' => 'approved',
        'decided_by' => $user->id,
    ]);

    $notification = Notification::first();
    expect($notification->message)->toContain('publish_content');
    expect($notification->message)->toContain('approved');
});

test('observer creates global notifications for approvals', function () {
    $approval = ApprovalRequest::factory()->create();

    $notification = Notification::first();
    expect($notification->user_id)->toBeNull();
});

test('observer sets correct action URL for approval notifications', function () {
    ApprovalRequest::factory()->create();

    $notification = Notification::first();
    expect($notification->action_url)->toBe('/approvals');
    expect($notification->action_label)->toBe('Review');
});

test('observer includes agent metadata when agent run exists', function () {
    $approval = ApprovalRequest::factory()->create();

    $notification = Notification::first();
    expect($notification->metadata)->toHaveKey('agent_name');
});
