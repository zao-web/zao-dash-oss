<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncProgress;
use App\Models\FinancialSnapshot;
use App\Models\QboAccount;
use App\Models\QboCustomer;
use App\Models\QboInvoice;
use App\Models\QboTransaction;
use App\Models\QuickBooksConnection;
use App\Services\QuickBooks\QuickBooksService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncQuickBooksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksSyncProgress;

    public function __construct(
        public ?int $connectionId = null,
        public bool $syncCustomers = true,
        public bool $syncInvoices = true,
        public bool $syncAccounts = true,
        public bool $syncTransactions = true,
        public bool $createSnapshot = true,
        public ?string $fromDate = null
    ) {}

    public function handle(QuickBooksService $qbService): void
    {
        $connections = $this->connectionId
            ? QuickBooksConnection::where('id', $this->connectionId)->get()
            : QuickBooksConnection::where('is_active', true)->get();

        foreach ($connections as $connection) {
            try {
                $this->syncConnection($connection, $qbService);
            } catch (\Exception $e) {
                Log::error('QuickBooks sync failed', [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function syncConnection(QuickBooksConnection $connection, QuickBooksService $qbService): void
    {
        $this->initSyncTracking($connection);

        try {
            if ($this->syncCustomers) {
                $this->syncCustomersData($connection, $qbService);
                $this->updateSyncProgress(20, 'customers');
            }

            if ($this->syncInvoices) {
                $this->syncInvoicesData($connection, $qbService);
                $this->updateSyncProgress(40, 'invoices');
            }

            if ($this->syncAccounts) {
                $this->syncAccountsData($connection, $qbService);
                $this->updateSyncProgress(60, 'accounts');
            }

            if ($this->syncTransactions) {
                $this->syncTransactionsData($connection, $qbService);
                $this->updateSyncProgress(80, 'transactions');
            }

            if ($this->createSnapshot) {
                $this->createFinancialSnapshot($connection, $qbService);
                $this->updateSyncProgress(95, 'snapshot');
            }

            $connection->update(['last_synced_at' => now()]);
            $this->completeSyncTracking();
        } catch (\Exception $e) {
            $this->failSyncTracking($e);
            throw $e;
        }
    }

    protected function syncCustomersData(QuickBooksConnection $connection, QuickBooksService $qbService): void
    {
        Log::info('Syncing QuickBooks customers');

        $customers = $qbService->listCustomers($connection);

        foreach ($customers as $custData) {
            QboCustomer::updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'qbo_id' => $custData['Id'],
                ],
                [
                    'display_name' => $custData['DisplayName'] ?? '',
                    'company_name' => $custData['CompanyName'] ?? null,
                    'given_name' => $custData['GivenName'] ?? null,
                    'family_name' => $custData['FamilyName'] ?? null,
                    'email' => $custData['PrimaryEmailAddr']['Address'] ?? null,
                    'phone' => $custData['PrimaryPhone']['FreeFormNumber'] ?? null,
                    'mobile' => $custData['Mobile']['FreeFormNumber'] ?? null,
                    'website' => $custData['WebAddr']['URI'] ?? null,
                    'billing_address' => $custData['BillAddr'] ?? null,
                    'shipping_address' => $custData['ShipAddr'] ?? null,
                    'balance' => $custData['Balance'] ?? 0,
                    'is_active' => $custData['Active'] ?? true,
                    'is_project' => $custData['IsProject'] ?? false,
                    'parent_id' => $custData['ParentRef']['value'] ?? null,
                    'notes' => $custData['Notes'] ?? null,
                    'taxable' => $custData['Taxable'] ?? true,
                    'currency' => $custData['CurrencyRef']['value'] ?? 'USD',
                    'sync_token' => $custData['SyncToken'] ?? null,
                    'metadata' => [
                        'create_time' => $custData['MetaData']['CreateTime'] ?? null,
                        'last_updated_time' => $custData['MetaData']['LastUpdatedTime'] ?? null,
                    ],
                ]
            );
        }
    }

    protected function syncInvoicesData(QuickBooksConnection $connection, QuickBooksService $qbService): void
    {
        Log::info('Syncing QuickBooks invoices');

        $fromDate = $this->fromDate
            ? Carbon::parse($this->fromDate)
            : ($connection->last_synced_at ?? now()->subMonths(12));

        $invoices = $qbService->listInvoices($connection, $fromDate);

        foreach ($invoices as $invData) {
            QboInvoice::updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'qbo_id' => $invData['Id'],
                ],
                [
                    'doc_number' => $invData['DocNumber'] ?? null,
                    'customer_id' => $invData['CustomerRef']['value'] ?? null,
                    'customer_name' => $invData['CustomerRef']['name'] ?? null,
                    'txn_date' => isset($invData['TxnDate'])
                        ? Carbon::parse($invData['TxnDate'])
                        : null,
                    'due_date' => isset($invData['DueDate'])
                        ? Carbon::parse($invData['DueDate'])
                        : null,
                    'total_amount' => $invData['TotalAmt'] ?? 0,
                    'balance' => $invData['Balance'] ?? 0,
                    'home_total_amount' => $invData['HomeTotalAmt'] ?? $invData['TotalAmt'] ?? 0,
                    'home_balance' => $invData['HomeBalance'] ?? $invData['Balance'] ?? 0,
                    'currency' => $invData['CurrencyRef']['value'] ?? 'USD',
                    'exchange_rate' => $invData['ExchangeRate'] ?? 1.0,
                    'ship_date' => isset($invData['ShipDate'])
                        ? Carbon::parse($invData['ShipDate'])
                        : null,
                    'tracking_num' => $invData['TrackingNum'] ?? null,
                    'billing_email' => $invData['BillEmail']['Address'] ?? null,
                    'ship_address' => $invData['ShipAddr'] ?? null,
                    'bill_address' => $invData['BillAddr'] ?? null,
                    'line_items' => $invData['Line'] ?? [],
                    'private_note' => $invData['PrivateNote'] ?? null,
                    'customer_memo' => $invData['CustomerMemo']['value'] ?? null,
                    'email_status' => $invData['EmailStatus'] ?? null,
                    'print_status' => $invData['PrintStatus'] ?? null,
                    'deposit' => $invData['Deposit'] ?? 0,
                    'allow_online_payment' => $invData['AllowOnlinePayment'] ?? false,
                    'allow_online_credit_card' => $invData['AllowOnlineCreditCardPayment'] ?? false,
                    'allow_online_ach' => $invData['AllowOnlineACHPayment'] ?? false,
                    'payment_status' => $this->determinePaymentStatus($invData),
                    'sync_token' => $invData['SyncToken'] ?? null,
                ]
            );
        }
    }

    protected function syncAccountsData(QuickBooksConnection $connection, QuickBooksService $qbService): void
    {
        Log::info('Syncing QuickBooks accounts');

        $accounts = $qbService->listAccounts($connection);

        foreach ($accounts as $acctData) {
            QboAccount::updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'qbo_id' => $acctData['Id'],
                ],
                [
                    'name' => $acctData['Name'] ?? '',
                    'fully_qualified_name' => $acctData['FullyQualifiedName'] ?? null,
                    'account_type' => $acctData['AccountType'] ?? null,
                    'account_sub_type' => $acctData['AccountSubType'] ?? null,
                    'classification' => $acctData['Classification'] ?? null,
                    'current_balance' => $acctData['CurrentBalance'] ?? 0,
                    'current_balance_with_sub' => $acctData['CurrentBalanceWithSubAccounts'] ?? 0,
                    'currency' => $acctData['CurrencyRef']['value'] ?? 'USD',
                    'is_active' => $acctData['Active'] ?? true,
                    'is_sub_account' => $acctData['SubAccount'] ?? false,
                    'parent_id' => $acctData['ParentRef']['value'] ?? null,
                    'description' => $acctData['Description'] ?? null,
                    'account_number' => $acctData['AcctNum'] ?? null,
                    'sync_token' => $acctData['SyncToken'] ?? null,
                ]
            );
        }
    }

    protected function syncTransactionsData(QuickBooksConnection $connection, QuickBooksService $qbService): void
    {
        Log::info('Syncing QuickBooks transactions');

        $fromDate = $this->fromDate
            ? Carbon::parse($this->fromDate)
            : now()->subMonths(3);

        $transactions = $qbService->listTransactions($connection, $fromDate);

        foreach ($transactions as $txnData) {
            QboTransaction::updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'qbo_id' => $txnData['Id'],
                    'txn_type' => $txnData['TxnType'] ?? 'Unknown',
                ],
                [
                    'txn_date' => isset($txnData['TxnDate'])
                        ? Carbon::parse($txnData['TxnDate'])
                        : null,
                    'doc_number' => $txnData['DocNumber'] ?? null,
                    'total_amount' => $txnData['TotalAmt'] ?? 0,
                    'account_id' => $txnData['AccountRef']['value'] ?? null,
                    'account_name' => $txnData['AccountRef']['name'] ?? null,
                    'entity_id' => $txnData['EntityRef']['value'] ?? null,
                    'entity_name' => $txnData['EntityRef']['name'] ?? null,
                    'entity_type' => $txnData['EntityRef']['type'] ?? null,
                    'currency' => $txnData['CurrencyRef']['value'] ?? 'USD',
                    'exchange_rate' => $txnData['ExchangeRate'] ?? 1.0,
                    'line_items' => $txnData['Line'] ?? [],
                    'memo' => $txnData['PrivateNote'] ?? null,
                    'sync_token' => $txnData['SyncToken'] ?? null,
                ]
            );
        }
    }

    protected function createFinancialSnapshot(QuickBooksConnection $connection, QuickBooksService $qbService): void
    {
        Log::info('Creating financial snapshot');

        try {
            $reports = $qbService->getFinancialReports($connection);

            FinancialSnapshot::create([
                'connection_id' => $connection->id,
                'snapshot_date' => now()->toDateString(),
                'period_type' => 'monthly',
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'total_revenue' => $reports['total_revenue'] ?? 0,
                'total_expenses' => $reports['total_expenses'] ?? 0,
                'net_income' => $reports['net_income'] ?? 0,
                'gross_profit' => $reports['gross_profit'] ?? 0,
                'accounts_receivable' => $reports['accounts_receivable'] ?? 0,
                'accounts_payable' => $reports['accounts_payable'] ?? 0,
                'cash_on_hand' => $reports['cash_on_hand'] ?? 0,
                'total_assets' => $reports['total_assets'] ?? 0,
                'total_liabilities' => $reports['total_liabilities'] ?? 0,
                'total_equity' => $reports['total_equity'] ?? 0,
                'report_data' => $reports,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to create financial snapshot', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function determinePaymentStatus(array $invData): string
    {
        $total = $invData['TotalAmt'] ?? 0;
        $balance = $invData['Balance'] ?? 0;

        if ($balance <= 0) {
            return 'paid';
        }

        if ($balance < $total) {
            return 'partial';
        }

        $dueDate = isset($invData['DueDate']) ? Carbon::parse($invData['DueDate']) : null;
        if ($dueDate && $dueDate->isPast()) {
            return 'overdue';
        }

        return 'open';
    }
}
