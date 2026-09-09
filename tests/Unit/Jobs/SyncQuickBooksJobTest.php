<?php

use App\Jobs\SyncQuickBooksJob;
use App\Models\FinancialSnapshot;
use App\Models\QboAccount;
use App\Models\QboCustomer;
use App\Models\QboInvoice;
use App\Models\QboTransaction;
use App\Models\QuickBooksConnection;
use App\Services\QuickBooks\QuickBooksService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

test('job can be dispatched', function () {
    Queue::fake();

    SyncQuickBooksJob::dispatch();

    Queue::assertPushed(SyncQuickBooksJob::class);
});

test('job can be dispatched with all parameters', function () {
    Queue::fake();

    SyncQuickBooksJob::dispatch(
        connectionId: 1,
        syncCustomers: false,
        syncInvoices: true,
        syncAccounts: false,
        syncTransactions: true,
        createSnapshot: false,
        fromDate: '2024-01-01'
    );

    Queue::assertPushed(SyncQuickBooksJob::class, function ($job) {
        return $job->connectionId === 1
            && $job->syncCustomers === false
            && $job->syncInvoices === true
            && $job->syncAccounts === false
            && $job->syncTransactions === true
            && $job->createSnapshot === false
            && $job->fromDate === '2024-01-01';
    });
});

test('handle syncs all active connections', function () {
    $conn1 = QuickBooksConnection::factory()->create(['is_active' => true]);
    $conn2 = QuickBooksConnection::factory()->create(['is_active' => true]);
    $inactive = QuickBooksConnection::factory()->create(['is_active' => false]);

    $qbService = Mockery::mock(QuickBooksService::class);
    $qbService->shouldReceive('listCustomers')->twice()->andReturn([]);
    $qbService->shouldReceive('listInvoices')->twice()->andReturn([]);
    $qbService->shouldReceive('listAccounts')->twice()->andReturn([]);
    $qbService->shouldReceive('listTransactions')->twice()->andReturn([]);
    $qbService->shouldReceive('getFinancialReports')->twice()->andReturn([]);

    $job = new SyncQuickBooksJob;
    $job->handle($qbService);
});

test('handle syncs customers', function () {
    $connection = QuickBooksConnection::factory()->create(['is_active' => true]);

    $customers = [
        [
            'Id' => '1',
            'DisplayName' => 'Acme Corp',
            'CompanyName' => 'Acme Corporation',
            'GivenName' => 'John',
            'FamilyName' => 'Doe',
            'PrimaryEmailAddr' => ['Address' => 'john@acme.com'],
            'Balance' => 1500.00,
            'Active' => true,
        ],
    ];

    $qbService = Mockery::mock(QuickBooksService::class);
    $qbService->shouldReceive('listCustomers')->once()->andReturn($customers);
    $qbService->shouldReceive('listInvoices')->andReturn([]);
    $qbService->shouldReceive('listAccounts')->andReturn([]);
    $qbService->shouldReceive('listTransactions')->andReturn([]);
    $qbService->shouldReceive('getFinancialReports')->andReturn([]);

    $job = new SyncQuickBooksJob(connectionId: $connection->id);
    $job->handle($qbService);

    expect(QboCustomer::count())->toBe(1);
    $customer = QboCustomer::first();
    expect($customer->display_name)->toBe('Acme Corp')
        ->and($customer->balance)->toBe(1500.00);
});

test('handle syncs invoices', function () {
    $connection = QuickBooksConnection::factory()->create(['is_active' => true]);

    $invoices = [
        [
            'Id' => '100',
            'DocNumber' => 'INV-001',
            'CustomerRef' => ['value' => '1', 'name' => 'Acme Corp'],
            'TxnDate' => '2024-01-15',
            'DueDate' => '2024-02-15',
            'TotalAmt' => 5000.00,
            'Balance' => 2500.00,
        ],
    ];

    $qbService = Mockery::mock(QuickBooksService::class);
    $qbService->shouldReceive('listCustomers')->andReturn([]);
    $qbService->shouldReceive('listInvoices')->once()->andReturn($invoices);
    $qbService->shouldReceive('listAccounts')->andReturn([]);
    $qbService->shouldReceive('listTransactions')->andReturn([]);
    $qbService->shouldReceive('getFinancialReports')->andReturn([]);

    $job = new SyncQuickBooksJob(connectionId: $connection->id);
    $job->handle($qbService);

    expect(QboInvoice::count())->toBe(1);
    $invoice = QboInvoice::first();
    expect($invoice->doc_number)->toBe('INV-001')
        ->and($invoice->total_amount)->toBe(5000.00);
});

test('handle syncs accounts', function () {
    $connection = QuickBooksConnection::factory()->create(['is_active' => true]);

    $accounts = [
        [
            'Id' => '50',
            'Name' => 'Checking Account',
            'FullyQualifiedName' => 'Bank:Checking Account',
            'AccountType' => 'Bank',
            'AccountSubType' => 'Checking',
            'CurrentBalance' => 25000.00,
            'Active' => true,
        ],
    ];

    $qbService = Mockery::mock(QuickBooksService::class);
    $qbService->shouldReceive('listCustomers')->andReturn([]);
    $qbService->shouldReceive('listInvoices')->andReturn([]);
    $qbService->shouldReceive('listAccounts')->once()->andReturn($accounts);
    $qbService->shouldReceive('listTransactions')->andReturn([]);
    $qbService->shouldReceive('getFinancialReports')->andReturn([]);

    $job = new SyncQuickBooksJob(connectionId: $connection->id);
    $job->handle($qbService);

    expect(QboAccount::count())->toBe(1);
    $account = QboAccount::first();
    expect($account->name)->toBe('Checking Account')
        ->and($account->account_type)->toBe('Bank');
});

test('handle syncs transactions', function () {
    $connection = QuickBooksConnection::factory()->create(['is_active' => true]);

    $transactions = [
        [
            'Id' => '200',
            'TxnType' => 'Payment',
            'TxnDate' => '2024-01-20',
            'DocNumber' => 'PMT-001',
            'TotalAmt' => 1000.00,
            'AccountRef' => ['value' => '50', 'name' => 'Checking'],
        ],
    ];

    $qbService = Mockery::mock(QuickBooksService::class);
    $qbService->shouldReceive('listCustomers')->andReturn([]);
    $qbService->shouldReceive('listInvoices')->andReturn([]);
    $qbService->shouldReceive('listAccounts')->andReturn([]);
    $qbService->shouldReceive('listTransactions')->once()->andReturn($transactions);
    $qbService->shouldReceive('getFinancialReports')->andReturn([]);

    $job = new SyncQuickBooksJob(connectionId: $connection->id);
    $job->handle($qbService);

    expect(QboTransaction::count())->toBe(1);
    $transaction = QboTransaction::first();
    expect($transaction->txn_type)->toBe('Payment');
});

test('handle creates financial snapshot', function () {
    $connection = QuickBooksConnection::factory()->create(['is_active' => true]);

    $reports = [
        'total_revenue' => 50000,
        'total_expenses' => 30000,
        'net_income' => 20000,
        'accounts_receivable' => 10000,
    ];

    $qbService = Mockery::mock(QuickBooksService::class);
    $qbService->shouldReceive('listCustomers')->andReturn([]);
    $qbService->shouldReceive('listInvoices')->andReturn([]);
    $qbService->shouldReceive('listAccounts')->andReturn([]);
    $qbService->shouldReceive('listTransactions')->andReturn([]);
    $qbService->shouldReceive('getFinancialReports')->once()->andReturn($reports);

    $job = new SyncQuickBooksJob(connectionId: $connection->id);
    $job->handle($qbService);

    expect(FinancialSnapshot::count())->toBe(1);
    $snapshot = FinancialSnapshot::first();
    expect($snapshot->total_revenue)->toBe(50000)
        ->and($snapshot->net_income)->toBe(20000);
});

test('handle respects sync flags', function () {
    $connection = QuickBooksConnection::factory()->create(['is_active' => true]);

    $qbService = Mockery::mock(QuickBooksService::class);
    $qbService->shouldReceive('listCustomers')->once()->andReturn([]);
    $qbService->shouldNotReceive('listInvoices');
    $qbService->shouldNotReceive('listAccounts');
    $qbService->shouldReceive('listTransactions')->once()->andReturn([]);
    $qbService->shouldNotReceive('getFinancialReports');

    $job = new SyncQuickBooksJob(
        connectionId: $connection->id,
        syncCustomers: true,
        syncInvoices: false,
        syncAccounts: false,
        syncTransactions: true,
        createSnapshot: false
    );
    $job->handle($qbService);
});

test('handle updates connection last_synced_at', function () {
    $connection = QuickBooksConnection::factory()->create([
        'is_active' => true,
        'last_synced_at' => null,
    ]);

    $qbService = Mockery::mock(QuickBooksService::class);
    $qbService->shouldReceive('listCustomers')->andReturn([]);
    $qbService->shouldReceive('listInvoices')->andReturn([]);
    $qbService->shouldReceive('listAccounts')->andReturn([]);
    $qbService->shouldReceive('listTransactions')->andReturn([]);
    $qbService->shouldReceive('getFinancialReports')->andReturn([]);

    $job = new SyncQuickBooksJob(connectionId: $connection->id);
    $job->handle($qbService);

    $connection->refresh();
    expect($connection->last_synced_at)->not->toBeNull();
});

test('handle logs snapshot errors without failing', function () {
    Log::spy();

    $connection = QuickBooksConnection::factory()->create(['is_active' => true]);

    $qbService = Mockery::mock(QuickBooksService::class);
    $qbService->shouldReceive('listCustomers')->andReturn([]);
    $qbService->shouldReceive('listInvoices')->andReturn([]);
    $qbService->shouldReceive('listAccounts')->andReturn([]);
    $qbService->shouldReceive('listTransactions')->andReturn([]);
    $qbService->shouldReceive('getFinancialReports')
        ->andThrow(new Exception('Report error'));

    $job = new SyncQuickBooksJob(connectionId: $connection->id);
    $job->handle($qbService);

    Log::shouldHaveReceived('warning')
        ->with('Failed to create financial snapshot', Mockery::any());

    // Connection should still be updated
    $connection->refresh();
    expect($connection->last_synced_at)->not->toBeNull();
});

test('handle logs errors and continues', function () {
    Log::spy();

    $conn1 = QuickBooksConnection::factory()->create(['is_active' => true]);
    $conn2 = QuickBooksConnection::factory()->create(['is_active' => true]);

    $qbService = Mockery::mock(QuickBooksService::class);
    $qbService->shouldReceive('listCustomers')
        ->twice()
        ->andReturnUsing(function () {
            static $call = 0;
            if ($call++ === 0) {
                throw new Exception('API error');
            }

            return [];
        });
    $qbService->shouldReceive('listInvoices')->once()->andReturn([]);
    $qbService->shouldReceive('listAccounts')->once()->andReturn([]);
    $qbService->shouldReceive('listTransactions')->once()->andReturn([]);
    $qbService->shouldReceive('getFinancialReports')->once()->andReturn([]);

    $job = new SyncQuickBooksJob;
    $job->handle($qbService);

    Log::shouldHaveReceived('error')
        ->with('QuickBooks sync failed', Mockery::any());
});

test('job implements ShouldQueue interface', function () {
    $job = new SyncQuickBooksJob;

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('job uses required traits', function () {
    $traits = class_uses(SyncQuickBooksJob::class);

    expect($traits)->toContain(\Illuminate\Bus\Queueable::class)
        ->and($traits)->toContain(\Illuminate\Foundation\Bus\Dispatchable::class)
        ->and($traits)->toContain(\Illuminate\Queue\InteractsWithQueue::class)
        ->and($traits)->toContain(\Illuminate\Queue\SerializesModels::class);
});
