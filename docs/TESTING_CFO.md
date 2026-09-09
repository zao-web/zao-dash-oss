# CFO System Testing Guide

## Prerequisites

```bash
# Ensure migrations are run
php artisan migrate

# Seed test data
php artisan db:seed --class=CFOTestSeeder
```

---

## 1. Contractor Features (Team Member View)

### Test Payment Setup Flow

1. **Log in as any team member**
2. **Visit `/my/payment-setup`**
   - Should see onboarding steps: Personal Info → W-9 → Bank Details → Ready
   - If new user, a Contractor record is auto-created

3. **Update Personal Info**
   - Fill in name, email, phone, country
   - For US contractors, toggle "US Person" on

4. **Submit W-9** (US contractors only)
   - Upload a PDF file
   - System validates it's a PDF under 10MB

5. **Add Bank Details**
   - Enter bank account info for Wise recipient creation
   - System creates Wise recipient (mocked in dev)

### Test Invoice Submission

1. **Visit `/my/invoices`**
   - Should see list of invoices (if any)
   - "Create Invoice" button appears only if payment setup is complete

2. **Create Invoice** (`/my/invoices/create`)
   - Fill in: amount, description, invoice date
   - Can attach supporting files
   - Save as draft

3. **Submit Invoice**
   - From invoice list, click "Submit" on a draft
   - Status changes to "Submitted"
   - Invoice now awaits admin approval

### Test Payment History

1. **Visit `/my/payments`**
   - Shows completed payments from Wise
   - Summary: total received, pending, year-to-date

---

## 2. Admin Contractor Management

### Manage Contractors

1. **Visit `/contractors`**
   - List of all contractors with status
   - Filter by status, payment type

2. **View Contractor Detail** (`/contractors/{id}`)
   - See contractor info, W-9 status, bank details
   - View their invoices and payment history

3. **Approve/Reject Invoices** (`/contractor-invoices/pending`)
   - List of submitted invoices awaiting approval
   - Approve → status becomes "approved"
   - Reject → provide reason, contractor sees rejection

4. **Pay Invoice**
   - From approved invoice, click "Pay"
   - Creates approval request for Wise transfer
   - Owner must approve in approval queue

---

## 3. CFO Agent Tools (via Tinker)

```bash
php artisan tinker
```

### Financial Summary Tool

```php
$tool = app(\App\Agents\Tools\FinancialSummaryTool::class);
$result = $tool->execute(['include_wise' => true]);
print_r($result);
```

**Expected output:**
- Cash position from QBO accounts
- Wise multi-currency balances
- AR/AP aging summaries
- Financial metrics (current ratio, days cash on hand)

### Contractor Pending Payments Tool

```php
$tool = app(\App\Agents\Tools\ContractorGetPendingPaymentsTool::class);
$result = $tool->execute([]);
print_r($result);
```

**Expected output:**
- List of approved invoices awaiting payment
- Recurring payments due
- Total amount and count
- Overdue items flagged

### Wise Get Balances Tool

```php
$tool = app(\App\Agents\Tools\WiseGetBalancesTool::class);
$result = $tool->execute([]);
print_r($result);

// Filter by currency
$result = $tool->execute(['currency' => 'USD']);
```

**Note:** Without real Wise API credentials, this returns mock/empty data.

### Tax Calculate Quarterly Tool

```php
$tool = app(\App\Agents\Tools\TaxCalculateQuarterlyTool::class);
$result = $tool->execute([
    'quarter' => 4,
    'year' => 2024,
    'entity_type' => 's_corp',
    'state_code' => 'CA'
]);
print_r($result);
```

**Expected output:**
- YTD income from QuickBooks
- Projected annual income
- Quarterly estimated tax payment
- Tax breakdown (federal, state, SE tax)

### Tax Get Strategies Tool

```php
$tool = app(\App\Agents\Tools\TaxGetStrategiesTool::class);
$result = $tool->execute(['refresh' => true]);
print_r($result);
```

**Expected output:**
- List of applicable tax strategies
- Estimated savings per strategy
- Timing sensitivity (urgent, year-end, flexible)

### Tax Get 1099 Vendors Tool

```php
$tool = app(\App\Agents\Tools\TaxGet1099VendorsTool::class);
$result = $tool->execute(['year' => 2024]);
print_r($result);
```

**Expected output:**
- Vendors with $600+ payments
- W-9 status for each
- Filing deadline countdown

### Financial Forecast Tool

```php
$tool = app(\App\Agents\Tools\FinancialForecastTool::class);
$result = $tool->execute(['months_ahead' => 3]);
print_r($result);
```

**Expected output:**
- Historical trends analysis
- Month-by-month projections
- Known upcoming expenses included
- Confidence level assessment

---

## 4. CFO Agent (Full Integration)

### Via Command Palette

If command palette is configured:

1. Press `Cmd+K` (or configured shortcut)
2. Type "Pay contractors" or "Tax status"
3. CFO Agent should:
   - Gather relevant data using tools
   - Present findings
   - Create approval requests for payments

### Direct Agent Execution

```php
// In tinker
$agentService = app(\App\Services\Agent\AgentExecutionService::class);
$result = $agentService->execute('cfo', [
    'action' => 'pay_contractors'
]);
```

---

## 5. Approval Flow Testing

### Create a Payment Approval

```php
// In tinker - initiate a Wise transfer
$tool = app(\App\Agents\Tools\WiseInitiateTransferTool::class);
$result = $tool->execute([
    'recipient_id' => 'recipient-john-123',
    'amount' => 1000,
    'reason' => 'Test payment for invoice'
]);
print_r($result);
// Should show: status => 'pending_approval'
```

### View Pending Approvals

1. **Visit `/approvals`** (as owner)
2. Should see the pending Wise transfer
3. Approve or reject

---

## 6. Seeded Test Data Reference

| Entity | Count | Description |
|--------|-------|-------------|
| Contractors | 4 | John (active), Sarah (recurring), Ali (international), New (pending) |
| Invoices | 4 | 1 submitted, 2 approved (1 overdue), 1 draft |
| Wise Transfers | 2 | Completed payments to John and Sarah |
| Tax Calendar | 2 | Q4 estimated payment, 1099 deadline |
| Tax Strategies | 1 | Solo 401(k) contribution strategy |

---

## 7. Common Test Scenarios

### Scenario A: New Team Member Sets Up Payment

1. Create new user with role 'team_member'
2. Log in as that user
3. Visit `/my/payment-setup`
4. Complete all onboarding steps
5. Submit an invoice
6. Log in as owner, approve invoice
7. Verify invoice appears in payment queue

### Scenario B: CFO Agent Pays All Contractors

1. Ensure there are approved invoices pending payment
2. Trigger CFO agent with "pay contractors"
3. Agent should:
   - List all pending payments
   - Check Wise balances
   - Create transfer requests (pending approval)
4. Owner approves transfers
5. Verify invoices marked as paid

### Scenario C: End-of-Year Tax Prep

1. Trigger CFO agent with "tax status"
2. Agent should show:
   - Q4 estimated payment
   - 1099 vendor list
   - Applicable strategies
3. Request "Generate tax package"
4. Approve the request
5. Review generated package

---

## Troubleshooting

### "No active QuickBooks connection"
Most tax tools require QBO. Ensure you have:
```php
\App\Models\QuickBooksConnection::create([
    'user_id' => 1,
    'realm_id' => 'test-realm',
    'access_token' => encrypt('test-token'),
    'refresh_token' => encrypt('test-refresh'),
    'is_active' => true,
]);
```

### "No active Wise connection"
The seeder creates one, but you can manually create:
```php
\App\Models\WiseConnection::create([
    'user_id' => 1,
    'profile_id' => 'test-profile',
    'api_token' => encrypt('test-token'),
    'is_active' => true,
]);
```

### Tool returns empty data
Many tools call external APIs. In development without real credentials:
- Wise tools return empty/error (expected)
- QBO tools may return empty if no P&L data
- Tax calculations need QBO income data
