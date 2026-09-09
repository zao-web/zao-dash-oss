<?php

use App\Models\RfpProposal;

function makeProposal(array $attributes): RfpProposal
{
    $proposal = new RfpProposal;
    $proposal->forceFill($attributes);

    return $proposal;
}

it('strips a leading Executive Summary section from renderableSections', function () {
    $proposal = makeProposal([
        'executive_summary' => 'Lead summary.',
        'proposal_sections' => [
            ['title' => 'Executive Summary', 'content' => 'Duplicate summary content.'],
            ['title' => 'Understanding of Requirements', 'content' => 'Real content.'],
        ],
    ]);

    expect($proposal->renderableSections())
        ->toHaveCount(1)
        ->and($proposal->renderableSections()[0]['title'])->toBe('Understanding of Requirements');
});

it('keeps sections untouched when no Executive Summary section is present', function () {
    $sections = [
        ['title' => 'Understanding of Requirements', 'content' => 'A'],
        ['title' => 'Proposed Solution', 'content' => 'B'],
    ];

    $proposal = makeProposal([
        'executive_summary' => 'Summary.',
        'proposal_sections' => $sections,
    ]);

    expect($proposal->renderableSections())->toEqual($sections);
});

it('matches Executive Summary titles case-insensitively', function () {
    $proposal = makeProposal([
        'executive_summary' => 'Summary.',
        'proposal_sections' => [
            ['title' => '  EXECUTIVE SUMMARY ', 'content' => 'Dupe.'],
            ['title' => 'Pricing', 'content' => 'Pricing.'],
        ],
    ]);

    expect($proposal->renderableSections())->toHaveCount(1);
});

it('does not strip sections when executive_summary is null', function () {
    $proposal = makeProposal([
        'executive_summary' => null,
        'proposal_sections' => [
            ['title' => 'Executive Summary', 'content' => 'Only place this lives.'],
        ],
    ]);

    expect($proposal->renderableSections())->toHaveCount(1);
});

it('strips leading Executive Summary heading and body from full_content', function () {
    $markdown = <<<'MD'
## Executive Summary

This is the duplicated summary body.
It spans multiple lines.

## Understanding of Requirements

The real content begins here.
MD;

    $proposal = makeProposal([
        'executive_summary' => 'Summary.',
        'full_content' => $markdown,
    ]);

    $result = $proposal->renderableFullContent();

    expect($result)
        ->toContain('Understanding of Requirements')
        ->and($result)->not->toContain('duplicated summary body');
});

it('handles full_content without an Executive Summary heading', function () {
    $markdown = "## Understanding of Requirements\n\nContent.";

    $proposal = makeProposal([
        'executive_summary' => 'Summary.',
        'full_content' => $markdown,
    ]);

    expect($proposal->renderableFullContent())->toBe($markdown);
});

it('returns null full_content unchanged', function () {
    $proposal = makeProposal([
        'executive_summary' => 'Summary.',
        'full_content' => null,
    ]);

    expect($proposal->renderableFullContent())->toBeNull();
});

it('preserves full_content when executive_summary is null', function () {
    $markdown = "## Executive Summary\n\nOnly summary.";

    $proposal = makeProposal([
        'executive_summary' => null,
        'full_content' => $markdown,
    ]);

    expect($proposal->renderableFullContent())->toBe($markdown);
});
