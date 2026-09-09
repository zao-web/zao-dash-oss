<?php

use App\Services\Rfp\RfpPreFilter;

beforeEach(function () {
    $this->filter = new RfpPreFilter;
});

it('passes a typical municipal opportunity', function () {
    $result = $this->filter->evaluate([
        'organization' => 'City of Portland',
        'title' => 'Website redesign for visit Portland',
        'extraction_confidence' => 0.9,
    ]);

    expect($result['passed'])->toBeTrue();
});

it('rejects sam.gov urls', function () {
    $result = $this->filter->evaluate([
        'organization' => 'Some Office',
        'title' => 'Procurement opportunity',
        'url' => 'https://sam.gov/opp/12345',
        'extraction_confidence' => 0.9,
    ]);

    expect($result['passed'])->toBeFalse()
        ->and($result['reason'])->toBe('federal_blocked');
});

it('rejects federal department names', function () {
    $result = $this->filter->evaluate([
        'organization' => 'U.S. Department of Veterans Affairs',
        'title' => 'Web modernization',
        'extraction_confidence' => 0.9,
    ]);

    expect($result['passed'])->toBeFalse()
        ->and($result['reason'])->toBe('federal_blocked');
});

it('rejects GSA and military branches', function () {
    foreach (['General Services Administration', 'US Army', 'United States Marine Corps'] as $org) {
        $result = $this->filter->evaluate([
            'organization' => $org,
            'title' => 'Web services',
            'extraction_confidence' => 0.9,
        ]);

        expect($result['passed'])->toBeFalse()
            ->and($result['reason'])->toBe('federal_blocked');
    }
});

it('rejects international procurement', function () {
    $result = $this->filter->evaluate([
        'organization' => 'Government of Canada',
        'title' => 'Canadian tourism portal',
        'extraction_confidence' => 0.9,
    ]);

    expect($result['passed'])->toBeFalse()
        ->and($result['reason'])->toBe('non_us');
});

it('rejects low-confidence extractions', function () {
    $result = $this->filter->evaluate([
        'organization' => 'City of Portland',
        'title' => 'Vague mention of website work',
        'extraction_confidence' => 0.3,
    ]);

    expect($result['passed'])->toBeFalse()
        ->and($result['reason'])->toBe('low_confidence');
});

it('treats missing extraction_confidence as fully confident', function () {
    $result = $this->filter->evaluate([
        'organization' => 'Travel Oregon',
        'title' => 'Destination marketing website',
    ]);

    expect($result['passed'])->toBeTrue();
});

it('checks federal patterns before confidence (federal even at high confidence)', function () {
    $result = $this->filter->evaluate([
        'organization' => 'Department of Defense',
        'title' => 'Web redesign',
        'extraction_confidence' => 0.99,
    ]);

    expect($result['passed'])->toBeFalse()
        ->and($result['reason'])->toBe('federal_blocked');
});

it('does not false-positive on state/local government', function () {
    foreach (['State of Oregon Tourism', 'Travel Portland', 'Visit California', 'County of Multnomah Library'] as $org) {
        $result = $this->filter->evaluate([
            'organization' => $org,
            'title' => 'Web project',
            'extraction_confidence' => 0.9,
        ]);

        expect($result['passed'])->toBeTrue("expected {$org} to pass pre-filter");
    }
});
