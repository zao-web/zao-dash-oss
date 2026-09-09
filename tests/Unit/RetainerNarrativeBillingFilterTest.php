<?php

use App\Services\Reports\RetainerNarrativeService;

/**
 * Reach the protected parseResponse via a one-off subclass, so we can assert
 * the billing-topic filter on a realistic LLM payload.
 */
beforeEach(function () {
    $this->svc = new class(app(\App\Services\Reports\RetainerHealthService::class)) extends RetainerNarrativeService
    {
        public function parse(string $text): array
        {
            return $this->parseResponse($text);
        }
    };
});

it('drops a billing/invoice topic from the narrative', function () {
    $json = json_encode([
        'topics' => [
            ['title' => 'Invoice and Payment Processing', 'summary' => 'Processed invoice and payment for monthly retainer', 'estimated_hours' => 0.3, 'status' => 'completed', 'start_date' => '2026-06-01', 'end_date' => '2026-06-01', 'evidence' => []],
            ['title' => 'Plugin updates', 'summary' => 'Updated WordPress plugins', 'estimated_hours' => 1.5, 'status' => 'completed', 'start_date' => '2026-06-02', 'end_date' => '2026-06-02', 'evidence' => []],
        ],
        'value_summary' => 'Delivered maintenance work.',
    ]);

    $result = $this->svc->parse($json);

    $titles = array_column($result['topics'], 'title');
    expect($titles)->toBe(['Plugin updates']);
    // Billing hours must not inflate the total.
    expect($result['total_estimated_hours'])->toBe(1.5);
});

it('keeps deliverable payment work like a gateway integration', function () {
    $json = json_encode([
        'topics' => [
            ['title' => 'Payment gateway integration', 'summary' => 'Built Stripe payment checkout on the site', 'estimated_hours' => 6.0, 'status' => 'completed', 'start_date' => '2026-06-03', 'end_date' => '2026-06-04', 'evidence' => []],
        ],
        'value_summary' => 'Shipped checkout.',
    ]);

    $result = $this->svc->parse($json);

    expect(array_column($result['topics'], 'title'))->toBe(['Payment gateway integration']);
});

it('drops a plain billing topic and accounts payable work', function () {
    $json = json_encode([
        'topics' => [
            ['title' => 'Monthly billing', 'summary' => 'Reconciled the account', 'estimated_hours' => 0.5, 'status' => 'completed', 'start_date' => '2026-06-01', 'end_date' => '2026-06-01', 'evidence' => []],
            ['title' => 'SEO content', 'summary' => 'Published two articles', 'estimated_hours' => 3.0, 'status' => 'completed', 'start_date' => '2026-06-05', 'end_date' => '2026-06-05', 'evidence' => []],
        ],
        'value_summary' => 'Content delivered.',
    ]);

    $result = $this->svc->parse($json);

    expect(array_column($result['topics'], 'title'))->toBe(['SEO content']);
});
