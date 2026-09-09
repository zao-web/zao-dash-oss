<?php

use App\Services\PersonalFinance\StatementLineItemParserService;

it('parses dated statement line items and infers inflow and outflow direction', function () {
    $text = <<<'TEXT'
01/05 ACH CREDIT CLIENT PAYMENT 10,000.00 12,500.00
01/06 ONLINE TRANSFER TO OPERATING 4,000.00 8,500.00
01/07 PURCHASE AUTHORIZED ON 01/07 SP SPEARWERX LLC CARD 0070 -141.99 8,358.01
01/08 REFUND AMAZON 25.00 8,383.01
TOTAL DEPOSITS 10,025.00
TOTAL WITHDRAWALS 4,141.99
TEXT;

    $parsed = app(StatementLineItemParserService::class)->parse($text, 2025);

    expect($parsed['line_items'])->toHaveCount(4)
        ->and($parsed['line_items'][0])->toMatchArray([
            'transaction_date' => '2025-01-05',
            'description' => 'ACH CREDIT CLIENT PAYMENT',
            'amount' => 10000.0,
            'direction' => 'inflow',
        ])
        ->and($parsed['line_items'][1])->toMatchArray([
            'transaction_date' => '2025-01-06',
            'amount' => 4000.0,
            'direction' => 'outflow',
        ])
        ->and($parsed['line_items'][2])->toMatchArray([
            'transaction_date' => '2025-01-07',
            'amount' => 141.99,
            'direction' => 'outflow',
        ])
        ->and($parsed['line_items'][3])->toMatchArray([
            'transaction_date' => '2025-01-08',
            'amount' => 25.0,
            'direction' => 'inflow',
        ])
        ->and($parsed['line_item_summary'])->toMatchArray([
            'parsed_count' => 4,
            'inflow_count' => 2,
            'outflow_count' => 2,
            'unknown_direction_count' => 0,
            'parsed_deposits' => 10025.0,
            'parsed_withdrawals' => 4141.99,
            'usable_for_matching' => true,
        ]);
});

it('coalesces wrapped statement lines before parsing', function () {
    $text = <<<'TEXT'
01/09 ONLINE PAYMENT TO
FEATURE.COM SOFTWARE SUBSCRIPTION 172.51 8,210.50
TEXT;

    $parsed = app(StatementLineItemParserService::class)->parse($text, 2025);

    expect($parsed['line_items'])->toHaveCount(1)
        ->and($parsed['line_items'][0]['transaction_date'])->toBe('2025-01-09')
        ->and($parsed['line_items'][0]['description'])->toContain('FEATURE.COM SOFTWARE SUBSCRIPTION')
        ->and($parsed['line_items'][0]['direction'])->toBe('outflow')
        ->and($parsed['line_items'][0]['amount'])->toBe(172.51);
});
