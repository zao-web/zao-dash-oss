<?php

namespace App\Services\QuickBooks;

use App\Models\QboAccount;
use App\Models\QboCustomer;
use App\Models\QboInvoice;
use App\Models\QboTransaction;
use App\Models\QuickBooksConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class QuickBooksApiService
{
    protected QuickBooksOAuthService $oauth;

    protected string $environment;

    public function __construct(QuickBooksOAuthService $oauth)
    {
        $this->oauth = $oauth;
        $this->environment = config('services.quickbooks.environment', 'production');
    }

    protected function getBaseUrl(): string
    {
        return $this->environment === 'sandbox'
            ? 'https://sandbox-quickbooks.api.intuit.com'
            : 'https://quickbooks.api.intuit.com';
    }

    protected function client(QuickBooksConnection $connection): PendingRequest
    {
        $token = $this->oauth->getValidAccessToken($connection);

        return Http::withToken($token)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->baseUrl($this->getBaseUrl()."/v3/company/{$connection->realm_id}");
    }

    public function query(QuickBooksConnection $connection, string $sql): array
    {
        $response = $this->client($connection)->get('/query', [
            'query' => $sql,
        ]);

        if (! $response->successful()) {
            throw new \Exception('QBO query failed: '.$response->body());
        }

        return $response->json()['QueryResponse'] ?? [];
    }

    // Accounts
    public function syncAccounts(QuickBooksConnection $connection): int
    {
        $result = $this->query($connection, 'SELECT * FROM Account WHERE Active = true MAXRESULTS 1000');
        $count = 0;

        foreach ($result['Account'] ?? [] as $account) {
            QboAccount::updateOrCreate(
                [
                    'qbo_connection_id' => $connection->id,
                    'qbo_id' => $account['Id'],
                ],
                [
                    'name' => $account['Name'],
                    'account_type' => $account['AccountType'],
                    'account_sub_type' => $account['AccountSubType'] ?? null,
                    'current_balance' => $account['CurrentBalance'] ?? 0,
                    'currency' => $account['CurrencyRef']['value'] ?? 'USD',
                    'active' => $account['Active'] ?? true,
                    'synced_at' => now(),
                ]
            );
            $count++;
        }

        return $count;
    }

    // Customers
    public function syncCustomers(QuickBooksConnection $connection): int
    {
        $result = $this->query($connection, 'SELECT * FROM Customer MAXRESULTS 1000');
        $count = 0;

        foreach ($result['Customer'] ?? [] as $customer) {
            QboCustomer::updateOrCreate(
                [
                    'qbo_connection_id' => $connection->id,
                    'qbo_id' => $customer['Id'],
                ],
                [
                    'display_name' => $customer['DisplayName'],
                    'company_name' => $customer['CompanyName'] ?? null,
                    'email' => $customer['PrimaryEmailAddr']['Address'] ?? null,
                    'phone' => $customer['PrimaryPhone']['FreeFormNumber'] ?? null,
                    'balance' => $customer['Balance'] ?? 0,
                    'active' => $customer['Active'] ?? true,
                    'synced_at' => now(),
                ]
            );
            $count++;
        }

        return $count;
    }

    // Invoices
    public function syncInvoices(QuickBooksConnection $connection, ?string $fromDate = null): int
    {
        $sql = 'SELECT * FROM Invoice';
        if ($fromDate) {
            $sql .= " WHERE TxnDate >= '{$fromDate}'";
        }
        $sql .= ' MAXRESULTS 1000';

        $result = $this->query($connection, $sql);
        $count = 0;

        foreach ($result['Invoice'] ?? [] as $invoice) {
            $status = 'Open';
            if (($invoice['Balance'] ?? 0) == 0) {
                $status = 'Paid';
            } elseif (isset($invoice['DueDate']) && \Carbon\Carbon::parse($invoice['DueDate'])->isPast()) {
                $status = 'Overdue';
            }

            QboInvoice::updateOrCreate(
                [
                    'qbo_connection_id' => $connection->id,
                    'qbo_id' => $invoice['Id'],
                ],
                [
                    'doc_number' => $invoice['DocNumber'] ?? null,
                    'customer_id' => $invoice['CustomerRef']['value'] ?? '',
                    'customer_name' => $invoice['CustomerRef']['name'] ?? '',
                    'txn_date' => $invoice['TxnDate'],
                    'due_date' => $invoice['DueDate'] ?? null,
                    'total_amount' => $invoice['TotalAmt'] ?? 0,
                    'balance' => $invoice['Balance'] ?? 0,
                    'status' => $status,
                    'email_status' => $invoice['EmailStatus'] ?? null,
                    'line_items' => $invoice['Line'] ?? [],
                    'synced_at' => now(),
                ]
            );
            $count++;
        }

        return $count;
    }

    // Transactions (Purchases/Expenses)
    public function syncTransactions(QuickBooksConnection $connection, ?string $fromDate = null, ?string $toDate = null): int
    {
        $count = 0;

        // Sync Purchases (expenses)
        $count += $this->syncTransactionType($connection, 'Purchase', $fromDate, $toDate);

        // Sync Payments received
        $count += $this->syncTransactionType($connection, 'Payment', $fromDate, $toDate);

        // Sync Deposits
        $count += $this->syncTransactionType($connection, 'Deposit', $fromDate, $toDate);

        return $count;
    }

    protected function syncTransactionType(QuickBooksConnection $connection, string $type, ?string $fromDate, ?string $toDate): int
    {
        $sql = "SELECT * FROM {$type}";
        $conditions = [];

        if ($fromDate) {
            $conditions[] = "TxnDate >= '{$fromDate}'";
        }
        if ($toDate) {
            $conditions[] = "TxnDate <= '{$toDate}'";
        }

        if (! empty($conditions)) {
            $sql .= ' WHERE '.implode(' AND ', $conditions);
        }
        $sql .= ' MAXRESULTS 1000';

        $result = $this->query($connection, $sql);
        $count = 0;

        foreach ($result[$type] ?? [] as $txn) {
            QboTransaction::updateOrCreate(
                [
                    'qbo_connection_id' => $connection->id,
                    'qbo_id' => $txn['Id'],
                    'txn_type' => $type,
                ],
                [
                    'txn_date' => $txn['TxnDate'],
                    'amount' => $txn['TotalAmt'] ?? 0,
                    'customer_id' => $txn['CustomerRef']['value'] ?? null,
                    'customer_name' => $txn['CustomerRef']['name'] ?? null,
                    'vendor_id' => $txn['VendorRef']['value'] ?? null,
                    'vendor_name' => $txn['VendorRef']['name'] ?? null,
                    'description' => $txn['PrivateNote'] ?? null,
                    'synced_at' => now(),
                ]
            );
            $count++;
        }

        return $count;
    }

    // Reports
    public function getProfitAndLoss(QuickBooksConnection $connection, string $startDate, string $endDate): array
    {
        $response = $this->client($connection)->get('/reports/ProfitAndLoss', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get P&L report: '.$response->body());
        }

        return $response->json();
    }

    public function getBalanceSheet(QuickBooksConnection $connection, string $asOfDate): array
    {
        $response = $this->client($connection)->get('/reports/BalanceSheet', [
            'date' => $asOfDate,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get balance sheet: '.$response->body());
        }

        return $response->json();
    }

    public function getCashFlow(QuickBooksConnection $connection, string $startDate, string $endDate): array
    {
        $response = $this->client($connection)->get('/reports/CashFlow', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get cash flow report: '.$response->body());
        }

        return $response->json();
    }

    // Sync all
    public function syncAll(QuickBooksConnection $connection): array
    {
        $results = [
            'accounts' => $this->syncAccounts($connection),
            'customers' => $this->syncCustomers($connection),
            'invoices' => $this->syncInvoices($connection),
            'transactions' => $this->syncTransactions($connection, now()->subYear()->toDateString()),
        ];

        $connection->update(['last_synced_at' => now()]);

        return $results;
    }

    // =========================================================================
    // WRITE OPERATIONS (Bookkeeping)
    // =========================================================================

    /**
     * Create a customer in QuickBooks.
     */
    public function createCustomer(QuickBooksConnection $connection, array $data): array
    {
        $payload = [
            'DisplayName' => $data['display_name'],
        ];

        if (! empty($data['company_name'])) {
            $payload['CompanyName'] = $data['company_name'];
        }
        if (! empty($data['email'])) {
            $payload['PrimaryEmailAddr'] = ['Address' => $data['email']];
        }
        if (! empty($data['phone'])) {
            $payload['PrimaryPhone'] = ['FreeFormNumber' => $data['phone']];
        }
        if (! empty($data['billing_address'])) {
            $payload['BillAddr'] = $data['billing_address'];
        }
        if (! empty($data['notes'])) {
            $payload['Notes'] = $data['notes'];
        }

        $response = $this->client($connection)->post('/customer', $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to create customer: '.$response->body());
        }

        return $response->json()['Customer'] ?? [];
    }

    /**
     * Create an invoice in QuickBooks.
     */
    public function createInvoice(QuickBooksConnection $connection, array $data): array
    {
        $payload = [
            'CustomerRef' => ['value' => $data['customer_id']],
            'Line' => $this->buildInvoiceLines($data['line_items']),
        ];

        if (! empty($data['due_date'])) {
            $payload['DueDate'] = $data['due_date'];
        }
        if (! empty($data['txn_date'])) {
            $payload['TxnDate'] = $data['txn_date'];
        }
        if (! empty($data['customer_memo'])) {
            $payload['CustomerMemo'] = ['value' => $data['customer_memo']];
        }
        if (! empty($data['private_note'])) {
            $payload['PrivateNote'] = $data['private_note'];
        }
        if (! empty($data['billing_email'])) {
            $payload['BillEmail'] = ['Address' => $data['billing_email']];
        }

        $response = $this->client($connection)->post('/invoice', $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to create invoice: '.$response->body());
        }

        $invoice = $response->json()['Invoice'] ?? [];

        // Sync to local database
        if (! empty($invoice['Id'])) {
            $this->syncInvoiceToLocal($connection, $invoice);
        }

        return $invoice;
    }

    protected function buildInvoiceLines(array $lineItems): array
    {
        $lines = [];

        foreach ($lineItems as $item) {
            $line = [
                'DetailType' => 'SalesItemLineDetail',
                'Amount' => $item['amount'],
                'SalesItemLineDetail' => [
                    'Qty' => $item['quantity'] ?? 1,
                    'UnitPrice' => $item['unit_price'] ?? $item['amount'],
                ],
            ];

            if (! empty($item['description'])) {
                $line['Description'] = $item['description'];
            }
            if (! empty($item['item_id'])) {
                $line['SalesItemLineDetail']['ItemRef'] = ['value' => $item['item_id']];
            }
            if (! empty($item['service_date'])) {
                $line['SalesItemLineDetail']['ServiceDate'] = $item['service_date'];
            }

            $lines[] = $line;
        }

        return $lines;
    }

    protected function syncInvoiceToLocal(QuickBooksConnection $connection, array $invoice): void
    {
        $status = 'Open';
        if (($invoice['Balance'] ?? 0) == 0) {
            $status = 'Paid';
        } elseif (isset($invoice['DueDate']) && \Carbon\Carbon::parse($invoice['DueDate'])->isPast()) {
            $status = 'Overdue';
        }

        QboInvoice::updateOrCreate(
            [
                'qbo_connection_id' => $connection->id,
                'qbo_id' => $invoice['Id'],
            ],
            [
                'doc_number' => $invoice['DocNumber'] ?? null,
                'customer_id' => $invoice['CustomerRef']['value'] ?? '',
                'customer_name' => $invoice['CustomerRef']['name'] ?? '',
                'txn_date' => $invoice['TxnDate'],
                'due_date' => $invoice['DueDate'] ?? null,
                'total_amount' => $invoice['TotalAmt'] ?? 0,
                'balance' => $invoice['Balance'] ?? 0,
                'status' => $status,
                'email_status' => $invoice['EmailStatus'] ?? null,
                'line_items' => $invoice['Line'] ?? [],
                'synced_at' => now(),
            ]
        );
    }

    /**
     * Send an invoice via email.
     */
    public function sendInvoice(QuickBooksConnection $connection, string $invoiceId, ?string $email = null): array
    {
        $url = "/invoice/{$invoiceId}/send";
        if ($email) {
            $url .= '?sendTo='.urlencode($email);
        }

        $response = $this->client($connection)->post($url);

        if (! $response->successful()) {
            throw new \Exception('Failed to send invoice: '.$response->body());
        }

        return $response->json()['Invoice'] ?? [];
    }

    /**
     * Record a payment for an invoice.
     */
    public function recordPayment(QuickBooksConnection $connection, array $data): array
    {
        $payload = [
            'CustomerRef' => ['value' => $data['customer_id']],
            'TotalAmt' => $data['amount'],
        ];

        if (! empty($data['txn_date'])) {
            $payload['TxnDate'] = $data['txn_date'];
        }

        // Link to invoice(s)
        if (! empty($data['invoice_id'])) {
            $payload['Line'] = [
                [
                    'Amount' => $data['amount'],
                    'LinkedTxn' => [
                        [
                            'TxnId' => $data['invoice_id'],
                            'TxnType' => 'Invoice',
                        ],
                    ],
                ],
            ];
        }

        // Payment method
        if (! empty($data['payment_method_id'])) {
            $payload['PaymentMethodRef'] = ['value' => $data['payment_method_id']];
        }

        // Deposit account
        if (! empty($data['deposit_account_id'])) {
            $payload['DepositToAccountRef'] = ['value' => $data['deposit_account_id']];
        }

        if (! empty($data['private_note'])) {
            $payload['PrivateNote'] = $data['private_note'];
        }

        $response = $this->client($connection)->post('/payment', $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to record payment: '.$response->body());
        }

        return $response->json()['Payment'] ?? [];
    }

    /**
     * Create an expense (Purchase) in QuickBooks.
     */
    public function createExpense(QuickBooksConnection $connection, array $data): array
    {
        $payload = [
            'AccountRef' => ['value' => $data['account_id']],
            'PaymentType' => $data['payment_type'] ?? 'Cash', // Cash, Check, CreditCard
            'Line' => $this->buildExpenseLines($data['line_items']),
        ];

        if (! empty($data['txn_date'])) {
            $payload['TxnDate'] = $data['txn_date'];
        }
        if (! empty($data['vendor_id'])) {
            $payload['EntityRef'] = ['value' => $data['vendor_id'], 'type' => 'Vendor'];
        }
        if (! empty($data['private_note'])) {
            $payload['PrivateNote'] = $data['private_note'];
        }
        if (! empty($data['doc_number'])) {
            $payload['DocNumber'] = $data['doc_number'];
        }

        $response = $this->client($connection)->post('/purchase', $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to create expense: '.$response->body());
        }

        return $response->json()['Purchase'] ?? [];
    }

    protected function buildExpenseLines(array $lineItems): array
    {
        $lines = [];

        foreach ($lineItems as $item) {
            $line = [
                'DetailType' => 'AccountBasedExpenseLineDetail',
                'Amount' => $item['amount'],
                'AccountBasedExpenseLineDetail' => [
                    'AccountRef' => ['value' => $item['account_id']],
                ],
            ];

            if (! empty($item['description'])) {
                $line['Description'] = $item['description'];
            }
            if (! empty($item['customer_id'])) {
                $line['AccountBasedExpenseLineDetail']['CustomerRef'] = ['value' => $item['customer_id']];
            }
            if (! empty($item['billable'])) {
                $line['AccountBasedExpenseLineDetail']['BillableStatus'] = 'Billable';
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Create a vendor in QuickBooks.
     */
    public function createVendor(QuickBooksConnection $connection, array $data): array
    {
        $payload = [
            'DisplayName' => $data['display_name'],
        ];

        if (! empty($data['company_name'])) {
            $payload['CompanyName'] = $data['company_name'];
        }
        if (! empty($data['email'])) {
            $payload['PrimaryEmailAddr'] = ['Address' => $data['email']];
        }
        if (! empty($data['phone'])) {
            $payload['PrimaryPhone'] = ['FreeFormNumber' => $data['phone']];
        }

        $response = $this->client($connection)->post('/vendor', $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to create vendor: '.$response->body());
        }

        return $response->json()['Vendor'] ?? [];
    }

    /**
     * Create a bill (accounts payable) in QuickBooks.
     */
    public function createBill(QuickBooksConnection $connection, array $data): array
    {
        $payload = [
            'VendorRef' => ['value' => $data['vendor_id']],
            'Line' => $this->buildExpenseLines($data['line_items']),
        ];

        if (! empty($data['due_date'])) {
            $payload['DueDate'] = $data['due_date'];
        }
        if (! empty($data['txn_date'])) {
            $payload['TxnDate'] = $data['txn_date'];
        }
        if (! empty($data['private_note'])) {
            $payload['PrivateNote'] = $data['private_note'];
        }

        $response = $this->client($connection)->post('/bill', $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to create bill: '.$response->body());
        }

        return $response->json()['Bill'] ?? [];
    }

    /**
     * Pay a bill in QuickBooks.
     */
    public function payBill(QuickBooksConnection $connection, array $data): array
    {
        $payload = [
            'VendorRef' => ['value' => $data['vendor_id']],
            'TotalAmt' => $data['amount'],
            'Line' => [
                [
                    'Amount' => $data['amount'],
                    'LinkedTxn' => [
                        [
                            'TxnId' => $data['bill_id'],
                            'TxnType' => 'Bill',
                        ],
                    ],
                ],
            ],
        ];

        if (! empty($data['txn_date'])) {
            $payload['TxnDate'] = $data['txn_date'];
        }
        if (! empty($data['check_payment'])) {
            $payload['PayType'] = 'Check';
            $payload['CheckPayment'] = [
                'BankAccountRef' => ['value' => $data['bank_account_id']],
            ];
        }

        $response = $this->client($connection)->post('/billpayment', $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to pay bill: '.$response->body());
        }

        return $response->json()['BillPayment'] ?? [];
    }

    /**
     * Get list of items/services.
     */
    public function getItems(QuickBooksConnection $connection): array
    {
        $result = $this->query($connection, 'SELECT * FROM Item WHERE Active = true MAXRESULTS 1000');

        return $result['Item'] ?? [];
    }

    /**
     * Get list of vendors.
     */
    public function getVendors(QuickBooksConnection $connection): array
    {
        $result = $this->query($connection, 'SELECT * FROM Vendor WHERE Active = true MAXRESULTS 1000');

        return $result['Vendor'] ?? [];
    }

    /**
     * Get list of payment methods.
     */
    public function getPaymentMethods(QuickBooksConnection $connection): array
    {
        $result = $this->query($connection, 'SELECT * FROM PaymentMethod WHERE Active = true MAXRESULTS 100');

        return $result['PaymentMethod'] ?? [];
    }

    // =========================================================================
    // EXPENSE CATEGORIZATION (Bookkeeping Agent)
    // =========================================================================

    /**
     * Get expense accounts (for categorization options).
     */
    public function getExpenseAccounts(QuickBooksConnection $connection): array
    {
        $result = $this->query(
            $connection,
            "SELECT * FROM Account WHERE AccountType = 'Expense' AND Active = true MAXRESULTS 500"
        );

        return $result['Account'] ?? [];
    }

    /**
     * Get uncategorized or poorly categorized expenses.
     * These are expenses using generic accounts like "Uncategorized Expense"
     * or "Ask My Accountant".
     */
    public function getUncategorizedExpenses(
        QuickBooksConnection $connection,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        // First get the uncategorized/generic account IDs
        $genericAccounts = $this->query(
            $connection,
            "SELECT Id, Name FROM Account WHERE Name IN ('Uncategorized Expense', 'Ask My Accountant', 'Other Expenses', 'Miscellaneous') AND Active = true"
        );

        $genericAccountIds = array_map(
            fn ($acc) => $acc['Id'],
            $genericAccounts['Account'] ?? []
        );

        if (empty($genericAccountIds)) {
            return [];
        }

        // Fetch purchases and filter by account
        $sql = 'SELECT * FROM Purchase';
        $conditions = [];

        if ($fromDate) {
            $conditions[] = "TxnDate >= '{$fromDate}'";
        }
        if ($toDate) {
            $conditions[] = "TxnDate <= '{$toDate}'";
        }

        if (! empty($conditions)) {
            $sql .= ' WHERE '.implode(' AND ', $conditions);
        }
        $sql .= ' MAXRESULTS 500';

        $result = $this->query($connection, $sql);
        $purchases = $result['Purchase'] ?? [];

        // Filter to only those with uncategorized line items
        $uncategorized = [];
        foreach ($purchases as $purchase) {
            $hasUncategorizedLine = false;
            foreach ($purchase['Line'] ?? [] as $line) {
                if (isset($line['AccountBasedExpenseLineDetail']['AccountRef']['value'])) {
                    $accountId = $line['AccountBasedExpenseLineDetail']['AccountRef']['value'];
                    if (in_array($accountId, $genericAccountIds)) {
                        $hasUncategorizedLine = true;
                        break;
                    }
                }
            }
            if ($hasUncategorizedLine) {
                $uncategorized[] = $purchase;
            }
        }

        return $uncategorized;
    }

    /**
     * Get expenses by date range for review.
     */
    public function getExpenses(
        QuickBooksConnection $connection,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?int $limit = 100
    ): array {
        $sql = 'SELECT * FROM Purchase';
        $conditions = [];

        if ($fromDate) {
            $conditions[] = "TxnDate >= '{$fromDate}'";
        }
        if ($toDate) {
            $conditions[] = "TxnDate <= '{$toDate}'";
        }

        if (! empty($conditions)) {
            $sql .= ' WHERE '.implode(' AND ', $conditions);
        }
        $sql .= " ORDER BY TxnDate DESC MAXRESULTS {$limit}";

        $result = $this->query($connection, $sql);

        return $result['Purchase'] ?? [];
    }

    /**
     * Get a single expense by ID.
     */
    public function getExpense(QuickBooksConnection $connection, string $purchaseId): ?array
    {
        $response = $this->client($connection)->get("/purchase/{$purchaseId}");

        if (! $response->successful()) {
            return null;
        }

        return $response->json()['Purchase'] ?? null;
    }

    /**
     * Update expense category (re-categorize an expense line item).
     *
     * @param  string  $purchaseId  The Purchase ID to update
     * @param  string  $newAccountId  The new expense account ID for categorization
     * @param  int|null  $lineNum  Specific line number to update (null = update all lines)
     * @param  string|null  $memo  Optional memo/note for the change
     */
    public function updateExpenseCategory(
        QuickBooksConnection $connection,
        string $purchaseId,
        string $newAccountId,
        ?int $lineNum = null,
        ?string $memo = null
    ): array {
        // First, fetch the existing purchase
        $existing = $this->getExpense($connection, $purchaseId);

        if (! $existing) {
            throw new \Exception("Purchase not found: {$purchaseId}");
        }

        // Build updated line items
        $updatedLines = [];
        foreach ($existing['Line'] ?? [] as $index => $line) {
            if ($line['DetailType'] === 'AccountBasedExpenseLineDetail') {
                // Update this line if lineNum matches or we're updating all
                if ($lineNum === null || $index === $lineNum) {
                    $line['AccountBasedExpenseLineDetail']['AccountRef'] = [
                        'value' => $newAccountId,
                    ];
                }
            }
            $updatedLines[] = $line;
        }

        // Build update payload - must include Id and SyncToken
        $payload = [
            'Id' => $existing['Id'],
            'SyncToken' => $existing['SyncToken'],
            'AccountRef' => $existing['AccountRef'],
            'PaymentType' => $existing['PaymentType'],
            'Line' => $updatedLines,
        ];

        // Preserve other required fields
        if (isset($existing['TxnDate'])) {
            $payload['TxnDate'] = $existing['TxnDate'];
        }
        if (isset($existing['EntityRef'])) {
            $payload['EntityRef'] = $existing['EntityRef'];
        }

        // Add memo if provided
        if ($memo) {
            $payload['PrivateNote'] = ($existing['PrivateNote'] ?? '')."\n[Auto-categorized: {$memo}]";
        }

        $response = $this->client($connection)->post('/purchase', $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to update expense category: '.$response->body());
        }

        return $response->json()['Purchase'] ?? [];
    }

    /**
     * Get tax-deductible expense categories.
     * Returns accounts that are commonly used for tax deductions.
     */
    public function getTaxDeductibleCategories(QuickBooksConnection $connection): array
    {
        // Common tax-deductible expense account sub-types
        $taxDeductibleSubTypes = [
            'AdvertisingPromotional',
            'Auto',
            'BankCharges',
            'CommissionsAndFees',
            'Insurance',
            'InterestPaid',
            'LegalProfessionalFees',
            'OfficeExpenses',
            'OfficeGeneralAdministrativeExpenses',
            'RentOrLeaseOfBuildings',
            'RepairMaintenance',
            'Supplies',
            'TaxesPaid',
            'Travel',
            'TravelMeals',
            'Utilities',
            'PayrollExpenses',
            'CostOfLaborCos',
            'Depreciation',
        ];

        $result = $this->query(
            $connection,
            "SELECT * FROM Account WHERE AccountType = 'Expense' AND Active = true MAXRESULTS 500"
        );

        $accounts = $result['Account'] ?? [];

        // Filter to tax-deductible categories and add metadata
        $deductible = [];
        foreach ($accounts as $account) {
            $subType = $account['AccountSubType'] ?? '';
            $deductible[] = [
                'id' => $account['Id'],
                'name' => $account['Name'],
                'sub_type' => $subType,
                'tax_deductible' => in_array($subType, $taxDeductibleSubTypes),
                'tax_category' => $this->mapToTaxCategory($subType),
            ];
        }

        return $deductible;
    }

    /**
     * Map QBO account sub-type to IRS tax category.
     */
    protected function mapToTaxCategory(string $subType): string
    {
        $mapping = [
            'AdvertisingPromotional' => 'Advertising',
            'Auto' => 'Car and truck expenses',
            'BankCharges' => 'Other expenses',
            'CommissionsAndFees' => 'Commissions and fees',
            'Insurance' => 'Insurance (other than health)',
            'InterestPaid' => 'Interest - Mortgage/Other',
            'LegalProfessionalFees' => 'Legal and professional services',
            'OfficeExpenses' => 'Office expense',
            'RentOrLeaseOfBuildings' => 'Rent or lease - Vehicles/equipment/property',
            'RepairMaintenance' => 'Repairs and maintenance',
            'Supplies' => 'Supplies',
            'TaxesPaid' => 'Taxes and licenses',
            'Travel' => 'Travel',
            'TravelMeals' => 'Meals (50% deductible)',
            'Utilities' => 'Utilities',
            'PayrollExpenses' => 'Wages',
            'Depreciation' => 'Depreciation',
        ];

        return $mapping[$subType] ?? 'Other expenses';
    }

    /**
     * Suggest category for an expense based on vendor and description.
     */
    public function suggestCategory(
        QuickBooksConnection $connection,
        string $vendorName,
        ?string $description = null,
        ?float $amount = null
    ): array {
        // Common vendor-to-category mappings
        $vendorMappings = [
            // Software & Subscriptions
            'adobe' => 'OfficeExpenses',
            'microsoft' => 'OfficeExpenses',
            'google' => 'OfficeExpenses',
            'amazon web services' => 'OfficeExpenses',
            'aws' => 'OfficeExpenses',
            'slack' => 'OfficeExpenses',
            'zoom' => 'OfficeExpenses',
            'dropbox' => 'OfficeExpenses',

            // Travel & Transportation
            'uber' => 'Travel',
            'lyft' => 'Travel',
            'delta' => 'Travel',
            'united' => 'Travel',
            'american airlines' => 'Travel',
            'southwest' => 'Travel',
            'marriott' => 'Travel',
            'hilton' => 'Travel',
            'airbnb' => 'Travel',

            // Meals & Entertainment
            'doordash' => 'TravelMeals',
            'grubhub' => 'TravelMeals',
            'uber eats' => 'TravelMeals',
            'starbucks' => 'TravelMeals',

            // Office Supplies
            'staples' => 'Supplies',
            'office depot' => 'Supplies',
            'amazon' => 'Supplies', // Default for Amazon

            // Utilities
            'comcast' => 'Utilities',
            'at&t' => 'Utilities',
            'verizon' => 'Utilities',

            // Insurance
            'state farm' => 'Insurance',
            'geico' => 'Insurance',
            'progressive' => 'Insurance',

            // Banking
            'stripe' => 'BankCharges',
            'paypal' => 'BankCharges',
            'square' => 'BankCharges',

            // Auto
            'shell' => 'Auto',
            'chevron' => 'Auto',
            'exxon' => 'Auto',
            'jiffy lube' => 'Auto',

            // Professional Services
            'quickbooks' => 'LegalProfessionalFees',
            'gusto' => 'LegalProfessionalFees',
        ];

        $vendorLower = strtolower($vendorName);
        $suggestedSubType = null;

        foreach ($vendorMappings as $pattern => $subType) {
            if (str_contains($vendorLower, $pattern)) {
                $suggestedSubType = $subType;
                break;
            }
        }

        // Get the actual account matching this sub-type
        if ($suggestedSubType) {
            $accounts = $this->getExpenseAccounts($connection);
            foreach ($accounts as $account) {
                if (($account['AccountSubType'] ?? '') === $suggestedSubType) {
                    return [
                        'account_id' => $account['Id'],
                        'account_name' => $account['Name'],
                        'sub_type' => $suggestedSubType,
                        'tax_category' => $this->mapToTaxCategory($suggestedSubType),
                        'confidence' => 'high',
                        'reason' => "Matched vendor pattern: {$vendorName}",
                    ];
                }
            }
        }

        // If no match, suggest "Ask My Accountant" or return null
        return [
            'account_id' => null,
            'account_name' => null,
            'sub_type' => null,
            'tax_category' => null,
            'confidence' => 'low',
            'reason' => "No pattern match for vendor: {$vendorName}. Manual review recommended.",
        ];
    }

    /**
     * Bulk categorize expenses.
     */
    public function bulkCategorizeExpenses(
        QuickBooksConnection $connection,
        array $categorizations
    ): array {
        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($categorizations as $item) {
            try {
                $result = $this->updateExpenseCategory(
                    $connection,
                    $item['purchase_id'],
                    $item['account_id'],
                    $item['line_num'] ?? null,
                    $item['memo'] ?? null
                );
                $results['success'][] = [
                    'purchase_id' => $item['purchase_id'],
                    'new_account_id' => $item['account_id'],
                ];
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'purchase_id' => $item['purchase_id'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}
