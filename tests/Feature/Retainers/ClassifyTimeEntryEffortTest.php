<?php

use App\Jobs\ClassifyTimeEntryEffort;
use App\Models\TimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('classifies meeting from notes containing meeting keyword', function () {
    $entry = TimeEntry::factory()->make(['notes' => 'Weekly team meeting with client']);
    expect(app(ClassifyTimeEntryEffort::class)->classify($entry))->toBe('meeting');
});

it('classifies meeting from notes containing call keyword', function () {
    $entry = TimeEntry::factory()->make(['notes' => 'Phone call with resort manager']);
    expect(app(ClassifyTimeEntryEffort::class)->classify($entry))->toBe('meeting');
});

it('classifies meeting from notes containing sync keyword', function () {
    $entry = TimeEntry::factory()->make(['notes' => 'Quick sync on timeline']);
    expect(app(ClassifyTimeEntryEffort::class)->classify($entry))->toBe('meeting');
});

it('classifies review from notes containing review keyword', function () {
    $entry = TimeEntry::factory()->make(['notes' => 'Code review on booking feature']);
    expect(app(ClassifyTimeEntryEffort::class)->classify($entry))->toBe('review');
});

it('classifies deployment from notes containing deploy keyword', function () {
    $entry = TimeEntry::factory()->make(['notes' => 'Deploy new version to production']);
    expect(app(ClassifyTimeEntryEffort::class)->classify($entry))->toBe('deployment');
});

it('classifies planning from notes containing scope keyword', function () {
    $entry = TimeEntry::factory()->make(['notes' => 'Scope out the new feature request']);
    expect(app(ClassifyTimeEntryEffort::class)->classify($entry))->toBe('planning');
});

it('classifies communication from notes containing email keyword', function () {
    $entry = TimeEntry::factory()->make(['notes' => 'Email correspondence about invoice']);
    expect(app(ClassifyTimeEntryEffort::class)->classify($entry))->toBe('communication');
});

it('classifies development as default when no other keyword matches', function () {
    $entry = TimeEntry::factory()->make(['notes' => 'Building the new booking widget']);
    expect(app(ClassifyTimeEntryEffort::class)->classify($entry))->toBe('development');
});

it('does not reclassify entries that already have effort_type set', function () {
    $entry = TimeEntry::factory()->create([
        'notes' => 'Meeting about code review',
        'effort_type' => 'development',
    ]);

    (new ClassifyTimeEntryEffort)->handle();

    expect($entry->refresh()->effort_type)->toBe('development');
});
