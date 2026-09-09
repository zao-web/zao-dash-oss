# Native Invoicing System

Zao Dash includes a complete invoicing system that replaces Harvest. It handles invoice creation, PDF generation, PayPal payments, email delivery, reminders, and QuickBooks sync.

## Environment Variables

### Company Information
```env
COMPANY_NAME="Zao"
COMPANY_EMAIL="billing@example.com"
COMPANY_PHONE=""
COMPANY_ADDRESS=""
```

### ACH Bank Details (displayed on invoices)
```env
BANK_NAME="Your Bank"
BANK_ROUTING_NUMBER="123456789"
BANK_ACCOUNT_NUMBER="987654321"
BANK_ACCOUNT_NAME="Your Company LLC"
```

### PayPal Integration
```env
PAYPAL_CLIENT_ID=your-client-id
PAYPAL_CLIENT_SECRET=your-client-secret
PAYPAL_MODE=sandbox  # or 'live'
```

### QuickBooks (optional)
Configured via Settings > Integrations. OAuth tokens stored in `quickbooks_connections` table.

## Invoice Lifecycle

1. **Draft** - Created from time entries or manually
2. **Sent** - Emailed to client, PayPal invoice created, reminders scheduled
3. **Viewed** - Client opened the invoice link
4. **Partial** - Partial payment received
5. **Paid** - Fully paid
6. **Overdue** - Past due date
7. **Cancelled** - Voided (time entries unmarked as billed)

## Creating Invoices

### From Time Entries (programmatic)
```php
$invoiceService = app(InvoiceService::class);

// Get unbilled time
$entries = $invoiceService->getUnbilledTimeEntries($client);

// Create invoice with grouping
$invoice = $invoiceService->createFromTimeEntries($client, $entries, [
    'group_mode' => 'project',  // individual, project, task, date
    'subject' => 'December Services',
]);
```

### Adding Fixed Fees
```php
$invoiceService->addFixedFee($invoice, 'Website Redesign', 5000.00);
```

## Recurring Invoices (Retainers)

Clients can have automatic monthly invoices generated:

```php
$client->update([
    'recurring_invoice_enabled' => true,
    'recurring_invoice_amount' => 5000.00,
    'recurring_invoice_day' => 1,  // 1-28
    'recurring_invoice_auto_send' => true,
    'recurring_invoice_description' => 'Monthly Retainer',
    'recurring_invoice_project_id' => $project->id,  // optional
]);
```

The `GenerateRecurringInvoicesJob` runs daily at 6am and creates invoices on each client's invoice day.

## Sending Invoices

```bash
# From admin UI: Click "Send" on invoice detail page
# This triggers:
# 1. PayPal invoice creation
# 2. Email with payment link
# 3. Reminder scheduling
# 4. QuickBooks sync
```

## Reminders

Automatic reminders are scheduled when an invoice is sent:
- 3 days before due date
- On due date
- 7 days overdue
- 14 days overdue
- 30 days overdue

Run via: `SendInvoiceRemindersJob` (scheduled hourly)

## Overdue Detection

The `UpdateOverdueInvoicesJob` runs daily at 6:15am and marks unpaid invoices as "overdue" when past their due date. This enables accurate AR reporting and status display.

## PDF Generation

PDF generation uses a dual-engine approach:

1. **Primary: Browsershot (Chrome)** - Beautiful PDFs with full Tailwind CSS support, gradients, modern layouts
2. **Fallback: dompdf** - Pure PHP fallback when Chrome is not available (limited CSS support)

The system automatically detects if Chrome is available and uses Browsershot for high-quality PDFs. If Chrome is not available, it falls back to dompdf.

```php
$pdfGenerator = app(PdfInvoiceGenerator::class);
$path = $pdfGenerator->generate($invoice);

// Or generate and store directly
$storagePath = $pdfGenerator->generateAndStore($invoice);
```

### PDF Template

Template: `resources/views/invoices/pdf.blade.php`

The template uses Tailwind CSS (loaded via CDN) for modern, beautiful styling:
- Custom brand colors from client settings
- Gradient headers and accent colors
- Modern card-based layout
- PayPal payment button integration
- Professional typography

### Installing Chrome (Required for Beautiful PDFs)

#### Laravel Cloud / Deployment

Add to your deployment hooks:

```bash
./deploy/chrome.sh
```

This script:
- Detects architecture (x64 or ARM64)
- Downloads Chrome headless shell for x64, or uses Puppeteer for ARM64
- Installs to `$HOME/bin/chrome/`
- The PDF generator automatically finds Chrome in this location

#### Local Development

```bash
# Ubuntu/Debian
sudo apt-get install chromium-browser

# macOS
brew install chromium

# Or via npm (any platform)
npx puppeteer browsers install chrome
```

#### Verifying Chrome is Working

Check the Laravel logs after generating a PDF:
```
[INFO] PDF generated with Browsershot {"invoice": "INV-2024-001", "chrome": "/home/user/bin/chrome/chrome-headless-shell"}
```

If you see "falling back to dompdf" in the logs, Chrome is not properly installed.

## Client Portal

Clients can view their invoices at `/portal/invoices` and account statements at `/portal/statement`.

Public invoice links (no auth): `/invoice/{number}?token={hmac_token}`

## QuickBooks Sync

Invoices and payments sync to QuickBooks automatically:

- **On invoice send**: Creates QBO invoice, creates customer if needed
- **On payment received**: Creates QBO payment linked to invoice

Manual sync command:
```bash
php artisan qbo:sync-invoices --all
php artisan qbo:sync-invoices --invoices
php artisan qbo:sync-invoices --payments
```

## Admin Routes

| Route | Description |
|-------|-------------|
| `GET /invoices` | List all invoices |
| `GET /invoices/{id}` | View invoice details |
| `GET /invoices/{id}/pdf` | Preview PDF |
| `GET /invoices/{id}/pdf/download` | Download PDF |
| `POST /invoices/{id}/send` | Send to client |
| `POST /invoices/{id}/regenerate-pdf` | Regenerate PDF |

## Webhooks

### PayPal
`POST /webhooks/paypal` - Receives payment notifications and marks invoices as paid.

## Agent Tools

The AI assistant can help with invoicing via these tools:

| Tool | Description |
|------|-------------|
| `GetUnbilledTimeTool` | Fetch unbilled time entries for a client |
| `CreateInvoiceTool` | Create invoice from time entries/fixed fees (requires approval) |
| `SendInvoiceTool` | Send a draft invoice to client (requires approval) |
| `ListInvoicesTool` | Search and filter invoices |

## Invoice Update Notifications

When editing an invoice that has already been sent to a client, you can optionally notify them of the changes. This is useful when:

- Applying retainer hours that reduce the amount due
- Adjusting line items or amounts
- Making any change the client should be aware of

### How to Use

1. Navigate to the invoice edit page
2. Make your changes (notes, amounts for drafts, etc.)
3. Check "Notify client of changes" in the Client Notification section
4. Optionally add a summary of what changed (e.g., "Applied 5 retainer hours, reducing the amount due")
5. Click Save

### What the Client Receives

The client receives an email with:
- Updated invoice summary (number, dates, new amount due)
- Optional "What changed" explanation you provided
- Link to view the updated invoice online
- PayPal payment button (if PayPal is configured and amount > 0)
- Updated PDF attached

### When Notifications Are Available

The "Notify client" option only appears for invoices in these statuses:
- Sent
- Viewed
- Partial
- Overdue

Draft invoices don't show this option since they haven't been sent to the client yet.

### Programmatic Usage

```php
use App\Mail\InvoiceUpdatedMail;

// Send update notification manually
Mail::to($recipientEmail)->send(
    new InvoiceUpdatedMail(
        $invoice,
        $paymentLink,         // optional PayPal link
        'Applied retainer hours'  // optional summary
    )
);
```

## Email Recipients (To + CC)

Automated invoice emails (recurring auto-send and payment reminders) resolve their
recipients from the client record:

- **To**: `clients.billing_email`, falling back to the first client contact with an
  email address. If neither exists, auto-send skips invoice creation entirely and
  reminders are marked failed.
- **CC**: every valid address in `clients.billing_cc_emails`. The field is free
  text — separate addresses with commas, semicolons, or newlines. Invalid entries
  are silently dropped (`Client::billingCcList()`), so a stray character can't
  break sends.

Set both fields on the client settings page (`/clients/{slug}` → Settings →
Billing section) or from the edit modal on the Clients index. Manual sends from
the invoice page are unaffected — they have their own recipient picker that
already supports multiple addresses.

To answer "how do I send a recurring retainer invoice to another email address":
add it to **Billing CC Emails** on the client. No artisan command or deploy step
is needed beyond the `billing_cc_emails` column migration, which runs
automatically on deploy.

## UI Workflows

- **Create**: `/invoices/create` or buttons on Tasks, Projects, Clients pages
- **Edit**: Full editing for drafts, limited editing after sent
- **Notify on Update**: Check "Notify client of changes" when saving sent invoices
- **Record Payment**: Modal on invoice show page
- **Command Palette**: `Cmd+K` → "invoice" for navigation and actions

## Database Tables

- `invoices` - Main invoice records
- `invoice_lines` - Line items (time, fixed, expense, discount)
- `payments` - Payment records
- `invoice_reminders` - Scheduled reminders

## Dashboard KPIs

Financial metrics on the dashboard use the native `Invoice` model:
- Revenue MTD (paid invoices this month)
- Outstanding AR (unpaid sent/viewed/partial/overdue)
- Overdue amount and count
- Average days to pay

## Harvest Migration

Historical invoices from Harvest can be migrated to the native system.

### Migrate All Invoices

```bash
php artisan invoices:migrate-from-harvest
```

Options:
- `--dry-run` - Preview without creating records
- `--skip-existing` - Skip invoices already migrated

The migration:
1. Creates Invoice records from HarvestInvoice data
2. Sets correct `amount_due` based on Harvest `due_amount`
3. Creates InvoiceLine records from Harvest line items (if available) or invoice subject
4. Creates Payment records for paid invoices

### Backfill Existing Migrated Invoices

If invoices were migrated before line items were captured, run the backfill:

```bash
php artisan invoices:backfill-harvest
```

Options:
- `--dry-run` - Show what would be fixed
- `--invoice=1234` - Fix a specific invoice by number

The backfill:
1. Corrects `amount_due` where it doesn't match Harvest `due_amount`
2. Creates missing InvoiceLine records from Harvest data

### Line Items from Harvest

The Harvest sync now captures line items from the API. When migrating or backfilling:

1. If Harvest `line_items` data exists, individual lines are created
2. If not, a single line item is created from the invoice subject/total

To re-sync Harvest data with line items:

```bash
php artisan harvest:full-import
```

### PayPal Auto-Creation

When a client views an unpaid invoice via the public link, a PayPal invoice is automatically created if one doesn't exist. This ensures migrated invoices from Harvest have a way to accept online payments.
