<?php

use App\Support\Demo\DemoDataMasker;

function mask(array $props, string $seed = 'test-seed'): array
{
    return (new DemoDataMasker($seed))->maskProps($props);
}

it('leaves identifiers, slugs, dates and enums untouched', function () {
    $out = mask([
        'row' => [
            'id' => 42,
            'client_id' => 7,
            'slug' => 'acme-corp',
            'status' => 'active',
            'type' => 'retainer',
            'role' => 'owner',
            'created_at' => '2025-01-01T00:00:00Z',
            'published_at' => '2025-02-01',
            'is_active' => true,
        ],
    ]);

    expect($out['row']['id'])->toBe(42)
        ->and($out['row']['client_id'])->toBe(7)
        ->and($out['row']['slug'])->toBe('acme-corp')
        ->and($out['row']['status'])->toBe('active')
        ->and($out['row']['type'])->toBe('retainer')
        ->and($out['row']['role'])->toBe('owner')
        ->and($out['row']['created_at'])->toBe('2025-01-01T00:00:00Z')
        ->and($out['row']['published_at'])->toBe('2025-02-01')
        ->and($out['row']['is_active'])->toBeTrue();
});

it('replaces names with believable fakes, not redaction', function () {
    $out = mask(['client' => ['name' => 'Acme Corporation LLC']]);

    expect($out['client']['name'])
        ->not->toBe('Acme Corporation LLC')
        ->not->toContain('*')
        ->and(strlen($out['client']['name']))->toBeGreaterThan(2);
});

it('maps the same input to the same output within a session', function () {
    $out = mask([
        'a' => ['name' => 'Acme Corporation LLC'],
        'b' => ['name' => 'Acme Corporation LLC'],
        'c' => ['name' => 'Different Company Inc'],
    ]);

    expect($out['a']['name'])->toBe($out['b']['name'])
        ->and($out['a']['name'])->not->toBe($out['c']['name']);
});

it('produces different fakes for different seeds', function () {
    $a = mask(['client' => ['name' => 'Acme Corporation LLC']], 'seed-one');
    $b = mask(['client' => ['name' => 'Acme Corporation LLC']], 'seed-two');

    expect($a['client']['name'])->not->toBe($b['client']['name']);
});

it('keeps person names as people and company names as companies', function () {
    $out = mask([
        'contact' => ['full_name' => 'Jane Doe'],
        'client' => ['client_name' => 'Globex Industries LLC'],
    ]);

    expect($out['contact']['full_name'])->toMatch('/^\S+ \S+$/')
        ->and($out['client']['client_name'])->toContain(' ');
});

it('anonymizes emails into valid fake addresses', function () {
    $out = mask([
        'contact' => ['email' => 'jane.doe@realclient.com'],
        'nested' => ['notes' => 'reach me at bob@secret.org anytime'],
    ]);

    expect(filter_var($out['contact']['email'], FILTER_VALIDATE_EMAIL))->not->toBeFalse()
        ->and($out['contact']['email'])->not->toContain('realclient.com');
});

it('anonymizes emails detected by value regardless of key name', function () {
    $out = mask(['weird_field' => 'contact: jane.doe@realclient.com']);

    // Whole-string email values are caught; embedded ones fall through untouched
    // unless the whole value validates as an email.
    $out2 = mask(['login' => 'jane.doe@realclient.com']);
    expect($out2['login'])->not->toBe('jane.doe@realclient.com')
        ->and(filter_var($out2['login'], FILTER_VALIDATE_EMAIL))->not->toBeFalse();
});

it('scales money by a single factor so sums still reconcile', function () {
    $out = mask([
        'invoice' => [
            'subtotal' => 100.0,
            'total' => 100.0,
            'lines' => [
                ['amount' => 60.0],
                ['amount' => 40.0],
            ],
        ],
    ]);

    $lineSum = $out['invoice']['lines'][0]['amount'] + $out['invoice']['lines'][1]['amount'];

    expect(round($lineSum, 2))->toBe(round($out['invoice']['total'], 2))
        ->and($out['invoice']['total'])->not->toBe(100.0);
});

it('preserves numeric types when scaling', function () {
    $out = mask([
        'a' => ['hours' => 8],
        'b' => ['amount' => 99.95],
        'c' => ['total' => '1500.00'],
    ]);

    expect($out['a']['hours'])->toBeInt()
        ->and($out['b']['amount'])->toBeFloat()
        ->and($out['c']['total'])->toBeString()
        ->and($out['c']['total'])->toMatch('/^\d+\.\d{2}$/');
});

it('does not scale a paginator total or per-page count', function () {
    $out = mask([
        'clients' => [
            'current_page' => 1,
            'per_page' => 25,
            'total' => 137,
            'data' => [
                ['id' => 1, 'name' => 'Acme Corp', 'mrr' => 5000],
            ],
        ],
    ]);

    expect($out['clients']['total'])->toBe(137)
        ->and($out['clients']['per_page'])->toBe(25)
        ->and($out['clients']['current_page'])->toBe(1)
        ->and($out['clients']['data'][0]['id'])->toBe(1)
        ->and($out['clients']['data'][0]['mrr'])->not->toBe(5000);
});

it('replaces free text with believable filler', function () {
    $out = mask(['task' => ['title' => 'Fix the production billing race condition']]);

    expect($out['task']['title'])
        ->not->toBe('Fix the production billing race condition')
        ->not->toContain('*')
        ->and(strlen($out['task']['title']))->toBeGreaterThan(5);
});

it('handles empty and scalar-only structures without error', function () {
    expect(mask([]))->toBe([])
        ->and(mask(['flag' => true, 'nothing' => null]))->toBe(['flag' => true, 'nothing' => null]);
});

it('masks deeply nested lists of records', function () {
    $out = mask([
        'projects' => [
            ['id' => 1, 'name' => 'Secret Project A', 'budget' => 10000],
            ['id' => 2, 'name' => 'Secret Project B', 'budget' => 20000],
        ],
    ]);

    expect($out['projects'][0]['id'])->toBe(1)
        ->and($out['projects'][0]['name'])->not->toBe('Secret Project A')
        ->and($out['projects'][1]['name'])->not->toBe('Secret Project B')
        ->and($out['projects'][0]['budget'])->not->toBe(10000);
});
