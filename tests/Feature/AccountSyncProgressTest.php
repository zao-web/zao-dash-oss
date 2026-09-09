<?php

use App\Events\AccountSyncProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('broadcasts AccountSyncProgress with correct payload', function () {
    Event::fake([AccountSyncProgress::class]);

    $user = User::factory()->create(['role' => 'owner']);

    AccountSyncProgress::dispatch(
        $user->id,
        'syncing_account',
        'Syncing Chase Checking...',
        1,
        3,
        'Chase Checking'
    );

    Event::assertDispatched(AccountSyncProgress::class, function ($event) use ($user) {
        return $event->userId === $user->id
            && $event->step === 'syncing_account'
            && $event->accountName === 'Chase Checking'
            && $event->completed === 1
            && $event->total === 3;
    });
});

it('includes progress percentage in broadcast data', function () {
    $event = new AccountSyncProgress(
        userId: 1,
        step: 'syncing_account',
        message: 'Syncing account...',
        completed: 2,
        total: 4,
        accountName: 'Test Account'
    );

    $data = $event->broadcastWith();

    expect($data['progress'])->toBe(50.0);
    expect($data['step'])->toBe('syncing_account');
    expect($data['account_name'])->toBe('Test Account');
});

it('broadcasts on the correct private channel', function () {
    $event = new AccountSyncProgress(
        userId: 42,
        step: 'complete',
        message: 'Done',
        completed: 4,
        total: 4
    );

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0]->name)->toBe('private-user.42');
});

it('uses the correct broadcast event name', function () {
    $event = new AccountSyncProgress(
        userId: 1,
        step: 'starting',
        message: 'Starting...',
        completed: 0,
        total: 4
    );

    expect($event->broadcastAs())->toBe('account.sync.progress');
});
