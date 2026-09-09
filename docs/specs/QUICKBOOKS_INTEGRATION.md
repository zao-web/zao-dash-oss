# QuickBooks Online Integration Technical Spec

## Overview

Integration with QuickBooks Online for automated bookkeeping, cash flow analysis, and business intelligence. Enables AI-driven financial insights and recommendations via a Business Analyst agent.

---

## 1. Use Cases

| Use Case | Description |
|----------|-------------|
| Cash flow tracking | Pull income/expenses automatically |
| Invoice sync | Sync invoices from Harvest → QBO |
| Expense categorization | Auto-categorize transactions |
| P&L analysis | Generate profit/loss insights |
| Business recommendations | AI-driven financial advice |
| Client profitability | Cross-reference with Harvest data |
| Tax preparation | Organize expenses by category |

---

## 2. Authentication

### OAuth 2.0 Flow
- Access tokens expire in **1 hour** (cannot be changed)
- Refresh tokens valid for **101 days**
- Must store and rotate refresh tokens on each use
- Scopes: `com.intuit.quickbooks.accounting`

```
User clicks "Connect QuickBooks"
    → Redirect to Intuit authorization URL
    → User authorizes access
    → Intuit redirects back with code
    → Exchange code for access + refresh tokens
    → Store refresh token (primary), access token (temporary)
```

---

## 3. Database Schema

### `quickbooks_connections` table
```sql
id                  BIGINT PRIMARY KEY
user_id             BIGINT FK
realm_id            VARCHAR(255) -- QBO company ID
company_name        VARCHAR(255)
access_token        TEXT (encrypted)
refresh_token       TEXT (encrypted)
access_token_expires_at TIMESTAMP
refresh_token_expires_at TIMESTAMP
last_synced_at      TIMESTAMP nullable
sync_enabled        BOOLEAN default true
created_at          TIMESTAMP
updated_at          TIMESTAMP

UNIQUE(realm_id)
```

### `qbo_accounts` table (Chart of Accounts)
```sql
id                  BIGINT PRIMARY KEY
qbo_connection_id   BIGINT FK
qbo_id              VARCHAR(50)
name                VARCHAR(255)
account_type        VARCHAR(100) -- Bank, Income, Expense, etc.
account_sub_type    VARCHAR(100) nullable
current_balance     DECIMAL(15,2)
currency            VARCHAR(10) default 'USD'
active              BOOLEAN default true
synced_at           TIMESTAMP
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX(qbo_connection_id)
INDEX(account_type)
```

### `qbo_transactions` table
```sql
id                  BIGINT PRIMARY KEY
qbo_connection_id   BIGINT FK
qbo_id              VARCHAR(50)
txn_type            VARCHAR(50) -- Invoice, Payment, Expense, Bill, Transfer, etc.
txn_date            DATE
amount              DECIMAL(15,2)
account_id          BIGINT FK nullable
customer_id         VARCHAR(50) nullable
customer_name       VARCHAR(255) nullable
vendor_id           VARCHAR(50) nullable
vendor_name         VARCHAR(255) nullable
description         TEXT nullable
category            VARCHAR(255) nullable
is_reconciled       BOOLEAN default false
client_id           BIGINT FK nullable -- matched to our client
project_id          BIGINT FK nullable -- matched to our project
synced_at           TIMESTAMP
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX(qbo_connection_id)
INDEX(txn_type)
INDEX(txn_date)
INDEX(client_id)
```

### `qbo_invoices` table
```sql
id                  BIGINT PRIMARY KEY
qbo_connection_id   BIGINT FK
qbo_id              VARCHAR(50)
doc_number          VARCHAR(50)
customer_id         VARCHAR(50)
customer_name       VARCHAR(255)
txn_date            DATE
due_date            DATE nullable
total_amount        DECIMAL(15,2)
balance             DECIMAL(15,2) -- amount still owed
status              VARCHAR(50) -- Paid, Open, Overdue, Voided
email_status        VARCHAR(50) nullable
line_items          JSON
harvest_invoice_id  BIGINT nullable FK -- linked Harvest invoice
client_id           BIGINT FK nullable
synced_at           TIMESTAMP
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX(qbo_connection_id)
INDEX(status)
INDEX(customer_id)
```

### `qbo_customers` table
```sql
id                  BIGINT PRIMARY KEY
qbo_connection_id   BIGINT FK
qbo_id              VARCHAR(50)
display_name        VARCHAR(255)
company_name        VARCHAR(255) nullable
email               VARCHAR(255) nullable
phone               VARCHAR(50) nullable
balance             DECIMAL(15,2) default 0
active              BOOLEAN default true
client_id           BIGINT FK nullable -- matched to our client
synced_at           TIMESTAMP
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX(qbo_connection_id)
INDEX(client_id)
```

### `financial_snapshots` table
```sql
id                  BIGINT PRIMARY KEY
qbo_connection_id   BIGINT FK
period_type         VARCHAR(20) -- daily, weekly, monthly, quarterly, yearly
period_start        DATE
period_end          DATE
total_income        DECIMAL(15,2)
total_expenses      DECIMAL(15,2)
net_profit          DECIMAL(15,2)
accounts_receivable DECIMAL(15,2)
accounts_payable    DECIMAL(15,2)
cash_on_hand        DECIMAL(15,2)
runway_days         INT nullable -- estimated days of runway
top_expense_categories JSON
top_income_sources  JSON
insights            JSON nullable -- AI-generated insights
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX(qbo_connection_id)
INDEX(period_type, period_start)
```

---

## 4. API Endpoints

### OAuth
```
GET  /auth/quickbooks                    - Initiate OAuth flow
GET  /auth/quickbooks/callback           - OAuth callback
```

### Connection Management
```
GET  /api/integrations/quickbooks/status     - Connection status
POST /api/integrations/quickbooks/disconnect - Disconnect
POST /api/integrations/quickbooks/refresh    - Force token refresh
```

### Sync
```
POST /api/integrations/quickbooks/sync/all      - Full sync
POST /api/integrations/quickbooks/sync/accounts - Sync chart of accounts
POST /api/integrations/quickbooks/sync/transactions - Sync transactions
POST /api/integrations/quickbooks/sync/invoices - Sync invoices
POST /api/integrations/quickbooks/sync/customers - Sync customers
```

### Data Access
```
GET  /api/integrations/quickbooks/accounts      - List accounts
GET  /api/integrations/quickbooks/transactions  - List transactions
GET  /api/integrations/quickbooks/invoices      - List invoices
GET  /api/integrations/quickbooks/customers     - List customers
GET  /api/integrations/quickbooks/cash-flow     - Cash flow summary
GET  /api/integrations/quickbooks/pnl           - P&L report
```

### Business Analysis
```
GET  /api/integrations/quickbooks/snapshots     - Financial snapshots
POST /api/integrations/quickbooks/analyze       - Run AI analysis
GET  /api/integrations/quickbooks/insights      - Get AI insights
GET  /api/integrations/quickbooks/recommendations - Business recommendations
```

### Client Matching
```
POST /api/integrations/quickbooks/customers/{id}/match - Match QBO customer to client
POST /api/integrations/quickbooks/auto-match          - Auto-match by name/email
```

---

## 5. Service Classes

### QuickBooksOAuthService
- `getAuthorizationUrl(state)` - Build OAuth URL with scopes
- `exchangeCodeForTokens(code, realmId)` - Exchange code for tokens
- `refreshAccessToken(connection)` - Refresh expired token
- `storeConnection(tokenData, realmId)` - Save connection
- `getValidAccessToken(connection)` - Get fresh access token

### QuickBooksApiService
- `query(sql)` - Execute QBO query language
- `getCompanyInfo()` - Get company details
- `getAccounts()` - Get chart of accounts
- `getTransactions(startDate, endDate)` - Get transactions
- `getInvoices(filters)` - Get invoices
- `getCustomers()` - Get customers
- `getVendors()` - Get vendors
- `getReport(reportType, params)` - Get financial report

### QuickBooksSyncService
- `syncAll(connection)` - Full sync
- `syncAccounts(connection)` - Sync chart of accounts
- `syncTransactions(connection, dateRange)` - Sync transactions
- `syncInvoices(connection)` - Sync invoices
- `syncCustomers(connection)` - Sync customers
- `matchCustomersToClients()` - Auto-match QBO customers to clients

### FinancialAnalysisService
- `generateSnapshot(period)` - Create financial snapshot
- `calculateCashFlow(dateRange)` - Calculate cash flow
- `calculateProfitLoss(dateRange)` - Calculate P&L
- `calculateRunway()` - Estimate cash runway
- `analyzeExpenses()` - Categorize and analyze expenses
- `generateInsights(snapshot)` - AI-generate financial insights
- `getRecommendations()` - AI business recommendations

---

## 6. Sync Strategy

### Initial Sync
1. Sync chart of accounts
2. Sync customers (for matching)
3. Sync last 12 months of transactions
4. Sync all open invoices
5. Auto-match customers to clients
6. Generate initial financial snapshot

### Ongoing Sync
- Every 4 hours: Sync transactions from last 7 days
- Every 4 hours: Sync invoice status changes
- Daily: Generate daily snapshot
- Weekly: Full transaction reconciliation
- Monthly: Generate monthly snapshot with insights

### Token Management
- Check token expiry before each API call
- Refresh if < 10 minutes remaining
- Store new refresh token immediately after each refresh
- Alert if refresh token approaching 101-day expiry

---

## 7. Configuration

```php
// config/services.php
'quickbooks' => [
    'client_id' => env('QUICKBOOKS_CLIENT_ID'),
    'client_secret' => env('QUICKBOOKS_CLIENT_SECRET'),
    'redirect_uri' => env('QUICKBOOKS_REDIRECT_URI'),
    'environment' => env('QUICKBOOKS_ENVIRONMENT', 'production'), // or 'sandbox'
    'base_url' => env('QUICKBOOKS_BASE_URL', 'https://quickbooks.api.intuit.com'),
],
```

```env
QUICKBOOKS_CLIENT_ID=your-client-id
QUICKBOOKS_CLIENT_SECRET=your-client-secret
QUICKBOOKS_REDIRECT_URI=http://localhost:8000/auth/quickbooks/callback
QUICKBOOKS_ENVIRONMENT=production
```

---

## 8. Business Analyst Agent Integration

The QBO data feeds into a Business Analyst agent:

### Data Inputs
- Cash flow trends (QBO)
- Revenue by client (QBO + Harvest)
- Expense categories (QBO)
- Profitability by project (Harvest + QBO)
- Invoice aging (QBO)
- Time tracking efficiency (Harvest)

### Agent Capabilities
- "How are we doing this month vs last month?"
- "Which clients are most profitable?"
- "What's our cash runway?"
- "Which expenses should we cut?"
- "Are we on track to hit revenue goals?"
- "Which invoices need follow-up?"

### Insight Types
```php
$insights = [
    'cash_flow_trend' => 'positive|negative|stable',
    'profitability_alert' => 'Client X margin dropped 20%',
    'expense_anomaly' => 'Software subscriptions up 40%',
    'revenue_forecast' => 'On track for $X this quarter',
    'action_items' => ['Follow up on Invoice #123', 'Review contractor costs'],
];
```

---

## 9. Harvest ↔ QuickBooks Sync

### Invoice Matching
- Match Harvest invoices to QBO invoices by:
  - Invoice number
  - Amount + date
  - Client name matching
- Link records: `harvest_invoices.qbo_invoice_id`

### Client Matching
- Match QBO customers to Zao clients by:
  - Company name (fuzzy match)
  - Email domain
  - Manual override

### Profitability Calculation
```
Revenue (QBO invoices)
- Direct costs (Harvest hours × cost rate)
- Allocated expenses (QBO)
= Project/Client profitability
```

---

## 10. Reports

### Cash Flow Report
```
Cash at start of period
+ Income received
- Expenses paid
- Payroll (if tracked)
= Cash at end of period

Breakdown by:
- Income by client
- Expenses by category
- Net by week/month
```

### Client Profitability Report
```
Client: Acme Corp
├── Revenue (QBO): $45,000
├── Hours (Harvest): 280
├── Direct Labor Cost: $28,000
├── Gross Margin: $17,000 (37.8%)
├── Allocated Overhead: $4,500
└── Net Profit: $12,500 (27.8%)
```

---

## 11. Security Considerations

- All tokens encrypted at rest
- Refresh tokens rotated on each use
- Connection automatically disabled if refresh fails
- Audit log of all financial data access
- Rate limiting on QBO API calls (respect Intuit limits)
