<?php

use App\Events\NotificationCreated;
use App\Models\AgentRun;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('observer creates notification when agent run completes', function () {
    $run = AgentRun::factory()->create(['status' => 'pending']);

    $run->update(['status' => 'completed']);

    expect(Notification::count())->toBe(1);

    $notification = Notification::first();
    expect($notification->type)->toBe('agent_run');
    expect($notification->title)->toBe('Agent Completed');
    expect($notification->severity)->toBe('success');
    expect($notification->icon)->toBe('✅');
    expect($notification->metadata['run_id'])->toBe($run->id);
    expect($notification->metadata['status'])->toBe('completed');
});

test('observer creates notification when agent run fails', function () {
    $run = AgentRun::factory()->create(['status' => 'running']);

    $run->update(['status' => 'failed']);

    expect(Notification::count())->toBe(1);

    $notification = Notification::first();
    expect($notification->type)->toBe('agent_run');
    expect($notification->title)->toBe('Agent Failed');
    expect($notification->severity)->toBe('error');
    expect($notification->icon)->toBe('❌');
});

test('observer broadcasts event when creating notification', function () {
    Event::fake([NotificationCreated::class]);

    $run = AgentRun::factory()->create(['status' => 'pending']);
    $run->update(['status' => 'completed']);

    Event::assertDispatched(NotificationCreated::class);
});

test('observer does not create notification for non-terminal status changes', function () {
    $run = AgentRun::factory()->create(['status' => 'pending']);

    $run->update(['status' => 'running']);

    expect(Notification::count())->toBe(0);
});

test('observer does not create notification when status does not change', function () {
    $run = AgentRun::factory()->create(['status' => 'running']);

    $run->update(['task' => 'Updated task description']);

    expect(Notification::count())->toBe(0);
});

test('observer notification includes correct action URL', function () {
    $run = AgentRun::factory()->create(['status' => 'running']);

    $run->update(['status' => 'completed']);

    $notification = Notification::first();
    expect($notification->action_url)->toBe("/agents/{$run->agent->slug}/runs/{$run->id}");
    expect($notification->action_label)->toBe('View Run');
});

test('observer notification includes agent metadata', function () {
    $run = AgentRun::factory()->create(['status' => 'pending']);

    $run->update(['status' => 'completed']);

    $notification = Notification::first();
    expect($notification->metadata)->toHaveKeys(['agent_id', 'agent_name', 'run_id', 'status']);
    expect($notification->metadata['agent_id'])->toBe($run->agent_id);
    expect($notification->metadata['agent_name'])->toBe($run->agent->name);
});

test('observer creates global notification', function () {
    $run = AgentRun::factory()->create(['status' => 'pending']);

    $run->update(['status' => 'failed']);

    $notification = Notification::first();
    expect($notification->user_id)->toBeNull();
});

test('observer handles multiple status changes correctly', function () {
    $run = AgentRun::factory()->create(['status' => 'pending']);

    // First change: pending -> running (no notification)
    $run->update(['status' => 'running']);
    expect(Notification::count())->toBe(0);

    // Second change: running -> failed (should notify)
    $run->update(['status' => 'failed']);
    expect(Notification::count())->toBe(1);
});
