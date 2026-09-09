<?php

use App\Services\Reports\RetainerNarrativeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function truncateBlock(string $block, int $max): string
{
    $svc = app(RetainerNarrativeService::class);
    $m = new ReflectionMethod($svc, 'truncateBlock');
    $m->setAccessible(true);

    return $m->invoke($svc, $block, $max);
}

it('leaves a block under the budget untouched', function () {
    $block = "line one\nline two\nline three";

    expect(truncateBlock($block, 10000))->toBe($block);
});

it('caps an oversized block to the budget and keeps the recent tail', function () {
    // 500 dated lines, oldest first — like a month of Slack evidence.
    $lines = collect(range(1, 500))
        ->map(fn ($i) => '[2026-05-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT)."] user: message number {$i} with some padding text")
        ->implode("\n");

    expect(strlen($lines))->toBeGreaterThan(24000);

    $capped = truncateBlock($lines, 24000);

    // Within budget (plus the short trim marker), and keeps the latest entries.
    expect(strlen($capped))->toBeLessThanOrEqual(24000 + 80)
        ->and($capped)->toContain('earlier entries trimmed')
        ->and($capped)->toContain('message number 500')
        ->and($capped)->not->toContain('message number 1 with');
});

it('does not split a line mid-way after trimming', function () {
    $lines = collect(range(1, 200))->map(fn ($i) => "row {$i} ".str_repeat('x', 200))->implode("\n");
    $capped = truncateBlock($lines, 5000);

    // First content line after the marker should be a whole "row N ..." line.
    $afterMarker = explode("\n", $capped, 2)[1] ?? '';
    expect($afterMarker)->toStartWith('row ');
});
