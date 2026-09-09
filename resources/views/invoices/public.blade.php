<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #{{ $invoice->number }} - {{ $company['name'] }}</title>
    <style>
        :root {
            --primary: {{ $branding['primary_color'] }};
            --text: #1f2937;
            --text-light: #6b7280;
            --border: #e5e7eb;
            --bg-light: #f9fafb;
            --success: #059669;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 16px;
            line-height: 1.6;
            color: var(--text);
            background: var(--bg-light);
            min-height: 100vh;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 24px;
        }

        .invoice-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .invoice-header {
            background: var(--primary);
            color: white;
            padding: 32px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .company-info h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .company-email {
            opacity: 0.9;
            font-size: 14px;
        }

        .invoice-badge {
            text-align: right;
        }

        .invoice-number {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 4px;
        }

        .invoice-status {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-paid { background: rgba(255,255,255,0.2); }
        .status-sent, .status-viewed { background: rgba(255,255,255,0.15); }
        .status-overdue { background: #dc2626; }
        .status-partial { background: #f59e0b; }

        .invoice-body {
            padding: 32px;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
            padding: 24px;
            background: var(--bg-light);
            border-radius: 8px;
        }

        .meta-item label {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-light);
            margin-bottom: 4px;
        }

        .meta-item value {
            display: block;
            font-size: 16px;
            font-weight: 600;
        }

        .meta-item.highlight value {
            font-size: 24px;
            color: var(--primary);
        }

        .bill-to {
            margin-bottom: 32px;
        }

        .bill-to h3 {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-light);
            margin-bottom: 8px;
        }

        .client-name {
            font-size: 18px;
            font-weight: 600;
        }

        .line-items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }

        .line-items th {
            text-align: left;
            padding: 12px 16px;
            background: var(--bg-light);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-light);
            font-weight: 600;
        }

        .line-items th:last-child {
            text-align: right;
        }

        .line-items td {
            padding: 16px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }

        .line-items td:last-child {
            text-align: right;
            font-family: 'SF Mono', Consolas, monospace;
        }

        .line-description {
            font-weight: 500;
        }

        .line-details {
            font-size: 14px;
            color: var(--text-light);
            margin-top: 4px;
        }

        .totals {
            margin-left: auto;
            width: 280px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
        }

        .total-row.grand {
            border-bottom: none;
            border-top: 2px solid var(--primary);
            padding-top: 16px;
            margin-top: 8px;
        }

        .total-label {
            color: var(--text-light);
        }

        .total-value {
            font-family: 'SF Mono', Consolas, monospace;
            font-weight: 500;
        }

        .grand .total-label {
            font-size: 16px;
            font-weight: 600;
            color: var(--text);
        }

        .grand .total-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }

        .payment-section {
            margin-top: 32px;
            padding-top: 32px;
            border-top: 1px solid var(--border);
        }

        .payment-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .pay-button {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: #0070ba;
            color: white;
            padding: 16px 32px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.2s;
        }

        .pay-button:hover {
            background: #005ea6;
        }

        .pay-button svg {
            width: 24px;
            height: 24px;
        }

        .paid-banner {
            background: var(--success);
            color: white;
            padding: 24px;
            text-align: center;
            border-radius: 8px;
            margin-top: 32px;
        }

        .paid-banner h3 {
            font-size: 20px;
            margin-bottom: 4px;
        }

        .paid-banner p {
            opacity: 0.9;
        }

        .download-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 24px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        .download-link:hover {
            text-decoration: underline;
        }

        .notes {
            margin-top: 32px;
            padding: 16px;
            background: var(--bg-light);
            border-radius: 8px;
        }

        .notes-title {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-light);
            margin-bottom: 8px;
        }

        .footer {
            text-align: center;
            padding: 24px;
            color: var(--text-light);
            font-size: 14px;
        }

        .payment-methods {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .payment-method h4 {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 12px;
        }

        .bank-details {
            background: var(--bg-light);
            border-radius: 8px;
            padding: 16px;
        }

        .bank-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
        }

        .bank-row:last-of-type {
            border-bottom: none;
        }

        .bank-label {
            font-size: 13px;
            color: var(--text-light);
        }

        .bank-value {
            font-size: 13px;
            font-weight: 500;
            color: var(--text);
            font-family: 'SF Mono', Consolas, monospace;
        }

        .bank-reference {
            margin-top: 12px;
            font-size: 13px;
            color: var(--text-light);
        }

        @media (max-width: 600px) {
            .invoice-header {
                flex-direction: column;
                gap: 16px;
            }

            .invoice-badge {
                text-align: left;
            }

            .meta-grid {
                grid-template-columns: 1fr 1fr;
            }

            .totals {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="invoice-card">
            <div class="invoice-header">
                <div class="company-info">
                    <h1>{{ $company['name'] }}</h1>
                    <div class="company-email">{{ $company['email'] }}</div>
                </div>
                <div class="invoice-badge">
                    <div class="invoice-number">Invoice #{{ $invoice->number }}</div>
                    <span class="invoice-status status-{{ $invoice->status }}">
                        {{ ucfirst($invoice->status) }}
                    </span>
                </div>
            </div>

            <div class="invoice-body">
                <div class="meta-grid">
                    <div class="meta-item">
                        <label>Issue Date</label>
                        <value>{{ $invoice->issue_date->format('M j, Y') }}</value>
                    </div>
                    <div class="meta-item">
                        <label>Due Date</label>
                        <value>{{ $invoice->due_date->format('M j, Y') }}</value>
                    </div>
                    <div class="meta-item">
                        <label>Terms</label>
                        <value>{{ $invoice->payment_terms }}</value>
                    </div>
                    <div class="meta-item highlight">
                        <label>Amount Due</label>
                        <value>${{ number_format($invoice->amount_due, 2) }}</value>
                    </div>
                </div>

                <div class="bill-to">
                    <h3>Bill To</h3>
                    <div class="client-name">{{ $client->name }}</div>
                </div>

                @if($invoice->subject)
                    <p style="margin-bottom: 24px; font-weight: 500;">Re: {{ $invoice->subject }}</p>
                @endif

                <table class="line-items">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th style="text-align: right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($lines as $line)
                            <tr>
                                <td>
                                    <div class="line-description">{{ $line->description }}</div>
                                    @if($line->details)
                                        <div class="line-details">{{ $line->details }}</div>
                                    @endif
                                    @if($line->quantity != 1 && $line->unit)
                                        <div class="line-details">
                                            {{ number_format($line->quantity, 2) }} {{ $line->unit }}
                                            @ ${{ number_format($line->unit_price, 2) }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    @if($line->amount < 0)
                                        -${{ number_format(abs($line->amount), 2) }}
                                    @else
                                        ${{ number_format($line->amount, 2) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="totals">
                    <div class="total-row">
                        <span class="total-label">Subtotal</span>
                        <span class="total-value">${{ number_format($invoice->subtotal, 2) }}</span>
                    </div>
                    @if($invoice->tax_rate > 0)
                        <div class="total-row">
                            <span class="total-label">Tax ({{ $invoice->tax_rate }}%)</span>
                            <span class="total-value">${{ number_format($invoice->tax_amount, 2) }}</span>
                        </div>
                    @endif
                    @if($invoice->amount_paid > 0)
                        <div class="total-row">
                            <span class="total-label">Paid</span>
                            <span class="total-value">-${{ number_format($invoice->amount_paid, 2) }}</span>
                        </div>
                    @endif
                    <div class="total-row grand">
                        <span class="total-label">Amount Due</span>
                        <span class="total-value">${{ number_format($invoice->amount_due, 2) }}</span>
                    </div>
                </div>

                @if($invoice->isPaid())
                    <div class="paid-banner">
                        <h3>Thank You!</h3>
                        <p>This invoice was paid on {{ $invoice->paid_at->format('M j, Y') }}</p>
                    </div>
                @elseif($invoice->amount_due > 0)
                    <div class="payment-section">
                        <h3 class="payment-title">Payment Options</h3>

                        <div class="payment-methods">
                            @if($paymentLink)
                                <div class="payment-method">
                                    <h4>Pay Online</h4>
                                    <a href="{{ $paymentLink }}" class="pay-button" target="_blank">
                                        <svg viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M7.076 21.337H2.47a.641.641 0 0 1-.633-.74L4.944.901C5.026.382 5.474 0 5.998 0h7.46c2.57 0 4.578.543 5.69 1.81 1.01 1.15 1.304 2.42 1.012 4.287-.023.143-.047.288-.077.437-.983 5.05-4.349 6.797-8.647 6.797h-2.19c-.524 0-.968.382-1.05.9l-1.12 7.106z"/>
                                        </svg>
                                        Pay with PayPal
                                    </a>
                                </div>
                            @endif

                            @if(config('app.bank_routing_number'))
                                <div class="payment-method">
                                    <h4>Pay by ACH/Wire Transfer</h4>
                                    <div class="bank-details">
                                        <div class="bank-row">
                                            <span class="bank-label">Bank</span>
                                            <span class="bank-value">{{ config('app.bank_name') }}</span>
                                        </div>
                                        <div class="bank-row">
                                            <span class="bank-label">Account Name</span>
                                            <span class="bank-value">{{ config('app.bank_account_name') }}</span>
                                        </div>
                                        <div class="bank-row">
                                            <span class="bank-label">Routing Number</span>
                                            <span class="bank-value">{{ config('app.bank_routing_number') }}</span>
                                        </div>
                                        <div class="bank-row">
                                            <span class="bank-label">Account Number</span>
                                            <span class="bank-value">{{ config('app.bank_account_number') }}</span>
                                        </div>
                                        <p class="bank-reference">
                                            Please reference invoice <strong>#{{ $invoice->number }}</strong> in your payment.
                                        </p>
                                    </div>
                                </div>
                            @endif

                            @if(!$paymentLink && !config('app.bank_routing_number'))
                                <p style="color: var(--text-light);">
                                    Please contact us at {{ $company['email'] }} for payment options.
                                </p>
                            @endif
                        </div>
                    </div>
                @endif

                @if($invoice->notes)
                    <div class="notes">
                        <div class="notes-title">Notes</div>
                        <div>{!! nl2br(e($invoice->notes)) !!}</div>
                    </div>
                @endif

                <a href="{{ route('invoices.public.pdf', ['invoice' => $invoice->number, 'token' => $invoice->public_token]) }}"
                   class="download-link">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10 12.586l-4.293-4.293-1.414 1.414L10 15.414l5.707-5.707-1.414-1.414L10 12.586z"/>
                        <path d="M10 0v12h-2V0h2z" transform="rotate(180 10 6)"/>
                        <path d="M2 18h16v2H2z"/>
                    </svg>
                    Download PDF
                </a>
            </div>
        </div>

        <div class="footer">
            <p>Questions? Contact us at {{ $company['email'] }}</p>
        </div>
    </div>
</body>
</html>
