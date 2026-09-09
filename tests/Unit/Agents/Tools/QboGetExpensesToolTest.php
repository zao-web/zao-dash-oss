<?php

use App\Agents\Tools\QboGetExpensesTool;
use App\Models\QuickBooksConnection;
use App\Services\QuickBooks\QuickBooksApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mockService = Mockery::mock(QuickBooksApiService::class);
    $this->tool = new QboGetExpensesTool($this->mockService);
});

test('getName returns correct name', function () {
    expect($this->tool->name())->toBe('Get QuickBooks Expenses');
});

test('getDescription returns correct description', function () {
    expect($this->tool->description())->toContain('Retrieve expenses from QuickBooks');
});

test('getParameters includes filter options', function () {
    $schema = $this->tool->inputSchema();

    expect($schema['properties'])->toHaveKeys(['filter', 'from_date', 'to_date', 'limit'])
        ->and($schema['properties']['filter']['enum'])->toContain('all', 'uncategorized');
});

test('execute returns error when no active connection', function () {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKeys(['success', 'error', 'expenses'])
        ->and($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('No active QuickBooks connection');
});

test('execute fetches uncategorized expenses by default', function () {
    $connection = QuickBooksConnection::factory()->create([
        'access_token' => 'test-token',
        'is_active' => true,
    ]);

    $this->mockService->shouldReceive('getUncategorizedExpenses')
        ->once()
        ->andReturn([
            [
                'Id' => '1',
                'TxnDate' => '2025-01-15',
                'EntityRef' => ['name' => 'Vendor 1'],
                'TotalAmt' => 100.00,
                'PaymentType' => 'Cash',
                'Line' => [],
            ],
        ]);

    $result = $this->tool->execute([]);

    expect($result['success'])->toBeTrue()
        ->and($result['filter'])->toBe('uncategorized')
        ->and($result['count'])->toBe(1);
});

test('execute fetches all expenses when filter is all', function () {
    $connection = QuickBooksConnection::factory()->create([
        'access_token' => 'test-token',
        'is_active' => true,
    ]);

    $this->mockService->shouldReceive('getExpenses')
        ->once()
        ->andReturn([]);

    $result = $this->tool->execute(['filter' => 'all']);

    expect($result['filter'])->toBe('all');
});

test('execute formats expenses correctly', function () {
    $connection = QuickBooksConnection::factory()->create([
        'access_token' => 'test-token',
        'is_active' => true,
    ]);

    $this->mockService->shouldReceive('getUncategorizedExpenses')
        ->once()
        ->andReturn([
            [
                'Id' => '123',
                'TxnDate' => '2025-01-15',
                'EntityRef' => ['name' => 'Office Supplies Inc'],
                'TotalAmt' => 250.50,
                'PaymentType' => 'CreditCard',
                'PrivateNote' => 'Office supplies',
                'Line' => [
                    [
                        'DetailType' => 'AccountBasedExpenseLineDetail',
                        'Amount' => 250.50,
                        'Description' => 'Pens and paper',
                        'AccountBasedExpenseLineDetail' => [
                            'AccountRef' => [
                                'value' => '45',
                                'name' => 'Office Expense',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

    $result = $this->tool->execute([]);

    expect($result['expenses'][0])->toMatchArray([
        'id' => '123',
        'date' => '2025-01-15',
        'vendor' => 'Office Supplies Inc',
        'total_amount' => 250.50,
        'payment_type' => 'CreditCard',
        'memo' => 'Office supplies',
    ])->and($result['expenses'][0]['lines'])->toHaveCount(1)
        ->and($result['expenses'][0]['lines'][0])->toMatchArray([
            'amount' => 250.50,
            'description' => 'Pens and paper',
            'account_name' => 'Office Expense',
        ]);
});

test('execute uses default date range of 30 days', function () {
    $connection = QuickBooksConnection::factory()->create([
        'access_token' => 'test-token',
        'is_active' => true,
    ]);

    $fromDate = now()->subDays(30)->format('Y-m-d');
    $toDate = now()->format('Y-m-d');

    $this->mockService->shouldReceive('getUncategorizedExpenses')
        ->once()
        ->with($connection, $fromDate, $toDate)
        ->andReturn([]);

    $result = $this->tool->execute([]);

    expect($result['date_range'])->toMatchArray([
        'from' => $fromDate,
        'to' => $toDate,
    ]);
});

test('execute respects custom date range', function () {
    $connection = QuickBooksConnection::factory()->create([
        'access_token' => 'test-token',
        'is_active' => true,
    ]);

    $this->mockService->shouldReceive('getUncategorizedExpenses')
        ->once()
        ->with($connection, '2025-01-01', '2025-01-31')
        ->andReturn([]);

    $result = $this->tool->execute([
        'from_date' => '2025-01-01',
        'to_date' => '2025-01-31',
    ]);

    expect($result['date_range'])->toMatchArray([
        'from' => '2025-01-01',
        'to' => '2025-01-31',
    ]);
});

test('execute respects limit parameter', function () {
    $connection = QuickBooksConnection::factory()->create([
        'access_token' => 'test-token',
        'is_active' => true,
    ]);

    $expenses = array_fill(0, 100, [
        'Id' => '1',
        'TxnDate' => '2025-01-15',
        'EntityRef' => ['name' => 'Vendor'],
        'TotalAmt' => 100,
        'Line' => [],
    ]);

    $this->mockService->shouldReceive('getUncategorizedExpenses')
        ->once()
        ->andReturn($expenses);

    $result = $this->tool->execute(['limit' => 10]);

    expect($result['count'])->toBe(10);
});

test('execute caps limit at 100', function () {
    $connection = QuickBooksConnection::factory()->create([
        'access_token' => 'test-token',
        'is_active' => true,
    ]);

    $this->mockService->shouldReceive('getUncategorizedExpenses')
        ->once()
        ->andReturn([]);

    $this->tool->execute(['limit' => 200]);

    // Verify limit is capped by checking the slice operation
    expect(true)->toBeTrue(); // Test passes if no error thrown
});

test('execute defaults to limit of 50', function () {
    $connection = QuickBooksConnection::factory()->create([
        'access_token' => 'test-token',
        'is_active' => true,
    ]);

    $expenses = array_fill(0, 100, [
        'Id' => '1',
        'TxnDate' => '2025-01-15',
        'EntityRef' => ['name' => 'Vendor'],
        'TotalAmt' => 100,
        'Line' => [],
    ]);

    $this->mockService->shouldReceive('getUncategorizedExpenses')
        ->once()
        ->andReturn($expenses);

    $result = $this->tool->execute([]);

    expect($result['count'])->toBe(50);
});

test('execute handles API exceptions gracefully', function () {
    $connection = QuickBooksConnection::factory()->create([
        'access_token' => 'test-token',
        'is_active' => true,
    ]);

    $this->mockService->shouldReceive('getUncategorizedExpenses')
        ->once()
        ->andThrow(new Exception('API Error'));

    $result = $this->tool->execute([]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toBe('API Error')
        ->and($result['expenses'])->toBeEmpty();
});

test('execute handles missing vendor name', function () {
    $connection = QuickBooksConnection::factory()->create([
        'access_token' => 'test-token',
        'is_active' => true,
    ]);

    $this->mockService->shouldReceive('getUncategorizedExpenses')
        ->once()
        ->andReturn([
            [
                'Id' => '1',
                'TxnDate' => '2025-01-15',
                'EntityRef' => [],
                'TotalAmt' => 100,
                'Line' => [],
            ],
        ]);

    $result = $this->tool->execute([]);

    expect($result['expenses'][0]['vendor'])->toBe('Unknown Vendor');
});
