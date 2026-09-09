<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #{{ $invoice->number }} - {{ $company['name'] }}</title>
    <style>
        @page {
            margin: 0;
            size: letter;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 13px;
            line-height: 1.4;
            color: #333;
            background: {{ ($isPdf ?? false) ? 'white' : '#f5f5f5' }};
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .page {
            @if($isPdf ?? false)
            padding: 48px 56px;
            @else
            max-width: 800px;
            margin: 24px auto;
            padding: 48px 56px;
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
            @endif
        }

        /* Header */
        .header {
            margin-bottom: 40px;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
        }

        .company-info {
            vertical-align: top;
        }

        .company-logo {
            max-height: 50px;
            max-width: 180px;
            margin-bottom: 8px;
        }

        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: {{ $branding['primary_color'] ?? '#2563eb' }};
            margin-bottom: 4px;
        }

        .company-details {
            font-size: 12px;
            color: #666;
            line-height: 1.5;
        }

        .invoice-title-cell {
            text-align: right;
            vertical-align: top;
        }

        .invoice-title {
            font-size: 32px;
            font-weight: bold;
            color: #333;
            letter-spacing: -1px;
            margin-bottom: 8px;
        }

        .invoice-number {
            font-size: 14px;
            color: #666;
        }

        /* Status badge */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 8px;
        }

        .status-paid {
            background: #059669;
            color: white;
        }

        .status-sent, .status-draft {
            background: {{ $branding['primary_color'] ?? '#2563eb' }};
            color: white;
        }

        .status-overdue {
            background: #dc2626;
            color: white;
        }

        .status-partial {
            background: #f59e0b;
            color: white;
        }

        /* Divider line */
        .divider {
            border-top: 2px solid {{ $branding['primary_color'] ?? '#2563eb' }};
            margin: 24px 0;
        }

        .divider-light {
            border-top: 1px solid #e0e0e0;
            margin: 24px 0;
        }

        /* Info section */
        .info-section {
            margin-bottom: 32px;
        }

        .info-table {
            width: 100%;
            border-collapse: collapse;
        }

        .info-table td {
            vertical-align: top;
            padding-right: 24px;
        }

        .info-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #888;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 14px;
            color: #333;
            font-weight: 500;
        }

        .info-value-large {
            font-size: 20px;
            color: {{ $branding['primary_color'] ?? '#2563eb' }};
            font-weight: bold;
        }

        /* Bill To / From */
        .addresses-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 32px;
        }

        .addresses-table td {
            vertical-align: top;
            width: 50%;
        }

        .address-block {
            padding-right: 24px;
        }

        .address-title {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #888;
            font-weight: bold;
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e0e0e0;
        }

        .address-name {
            font-size: 15px;
            font-weight: bold;
            color: #333;
            margin-bottom: 4px;
        }

        .address-detail {
            font-size: 13px;
            color: #666;
            line-height: 1.5;
        }

        /* Subject line */
        .subject-line {
            background: #f8f9fa;
            border-left: 3px solid {{ $branding['primary_color'] ?? '#2563eb' }};
            padding: 12px 16px;
            margin-bottom: 24px;
            font-size: 14px;
            color: #333;
        }

        /* Line items table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }

        .items-table th {
            text-align: left;
            padding: 12px 8px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #666;
            font-weight: bold;
            border-bottom: 2px solid #333;
        }

        .items-table th.align-right {
            text-align: right;
        }

        .items-table td {
            padding: 12px 8px;
            border-bottom: 1px solid #e8e8e8;
            vertical-align: top;
        }

        .items-table td.align-right {
            text-align: right;
        }

        .item-description {
            font-weight: 500;
            color: #333;
        }

        .item-details {
            font-size: 12px;
            color: #888;
            margin-top: 2px;
        }

        .item-amount {
            font-family: 'Courier New', Courier, monospace;
            font-weight: 500;
        }

        .discount-row td {
            color: #059669;
        }

        /* Totals */
        .totals-section {
            width: 100%;
            border-collapse: collapse;
        }

        .totals-spacer {
            width: 55%;
        }

        .totals-content {
            width: 45%;
        }

        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }

        .totals-table td {
            padding: 6px 0;
        }

        .totals-table .label {
            text-align: right;
            padding-right: 16px;
            color: #666;
            font-size: 13px;
        }

        .totals-table .value {
            text-align: right;
            font-family: 'Courier New', Courier, monospace;
            font-size: 13px;
        }

        .totals-table .total-row td {
            padding-top: 12px;
            border-top: 2px solid #333;
        }

        .totals-table .total-row .label {
            font-size: 14px;
            font-weight: bold;
            color: #333;
        }

        .totals-table .total-row .value {
            font-size: 18px;
            font-weight: bold;
            color: {{ $branding['primary_color'] ?? '#2563eb' }};
        }

        /* Amount due box */
        .amount-due-box {
            background: {{ $branding['primary_color'] ?? '#2563eb' }};
            color: white;
            padding: 20px 24px;
            margin-top: 32px;
            text-align: center;
        }

        .amount-due-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }

        .amount-due-amount {
            font-size: 28px;
            font-weight: bold;
            font-family: 'Courier New', Courier, monospace;
        }

        .amount-due-date {
            font-size: 12px;
            margin-top: 4px;
        }

        /* Paid banner */
        .paid-banner {
            background: #059669;
            color: white;
            padding: 20px 24px;
            margin-top: 32px;
            text-align: center;
        }

        .paid-banner-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .paid-banner-date {
            font-size: 13px;
        }

        /* Payment section */
        .payment-section {
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #e0e0e0;
        }

        .payment-title {
            font-size: 14px;
            font-weight: bold;
            color: #333;
            margin-bottom: 16px;
        }

        .payment-options {
            width: 100%;
            border-collapse: collapse;
        }

        .payment-options td {
            vertical-align: top;
            width: 50%;
            padding-right: 16px;
        }

        .payment-box {
            border: 1px solid #e0e0e0;
            padding: 16px;
        }

        .payment-box.highlight {
            background: #f0f7ff;
            border-color: #bdd4f0;
        }

        .payment-box-title {
            font-weight: bold;
            font-size: 13px;
            margin-bottom: 8px;
            color: #333;
        }

        .payment-box-desc {
            font-size: 12px;
            color: #666;
            margin-bottom: 12px;
        }

        .pay-button {
            display: inline-block;
            background: #0070ba;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            font-size: 13px;
            font-weight: bold;
        }

        /* Bank details */
        .bank-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            background: #f8f9fa;
        }

        .bank-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #e8e8e8;
            font-size: 12px;
        }

        .bank-table .bank-label {
            color: #666;
            width: 40%;
        }

        .bank-table .bank-value {
            font-family: 'Courier New', Courier, monospace;
            font-weight: 500;
            text-align: right;
        }

        .bank-reference {
            margin-top: 12px;
            font-size: 12px;
            color: #666;
        }

        /* Notes */
        .notes-section {
            margin-top: 24px;
            padding: 16px;
            background: #fffbeb;
            border: 1px solid #fde68a;
        }

        .notes-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #92400e;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .notes-text {
            font-size: 13px;
            color: #333;
            line-height: 1.5;
        }

        /* Footer */
        .footer {
            margin-top: 40px;
            padding-top: 16px;
            border-top: 1px solid #e0e0e0;
            text-align: center;
        }

        .footer-thanks {
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin-bottom: 4px;
        }

        .footer-contact {
            font-size: 12px;
            color: #666;
        }

        .footer-contact a {
            color: {{ $branding['primary_color'] ?? '#2563eb' }};
            text-decoration: none;
        }

        .footer-brand {
            margin-top: 8px;
            font-size: 11px;
            color: {{ $branding['primary_color'] ?? '#2563eb' }};
            font-style: italic;
        }

        /* Download link (online only) */
        @if(!($isPdf ?? false))
        .download-section {
            text-align: center;
            margin-top: 24px;
        }

        .download-link {
            color: {{ $branding['primary_color'] ?? '#2563eb' }};
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
        }

        .download-link:hover {
            text-decoration: underline;
        }
        @endif

        /* Page break control for PDF */
        @if($isPdf ?? false)
        .items-table {
            page-break-inside: auto;
        }

        .items-table tr {
            page-break-inside: avoid;
        }

        .totals-section {
            page-break-inside: avoid;
        }

        .payment-section {
            page-break-inside: avoid;
        }

        .footer {
            page-break-inside: avoid;
        }
        @endif

        /* Print styles */
        @media print {
            body {
                background: white;
            }

            .page {
                max-width: none;
                margin: 0;
                padding: 48px 56px;
                box-shadow: none;
            }

            .download-section {
                display: none;
            }
        }

        /* Responsive (online only) */
        @if(!($isPdf ?? false))
        @media (max-width: 640px) {
            .page {
                margin: 0;
                padding: 24px;
            }

            .header-table td {
                display: block;
                text-align: left;
            }

            .invoice-title-cell {
                text-align: left;
                margin-top: 16px;
            }

            .info-table td {
                display: block;
                padding-bottom: 12px;
            }

            .addresses-table td {
                display: block;
                width: 100%;
                padding-bottom: 16px;
            }

            .payment-options td {
                display: block;
                width: 100%;
                padding-bottom: 12px;
            }

            .totals-spacer {
                display: none;
            }

            .totals-content {
                width: 100%;
            }
        }
        @endif
    </style>
</head>
<body>
    <div class="page">
        {{-- HEADER --}}
        <div class="header">
            <table class="header-table">
                <tr>
                    <td class="company-info">
                        @if($company['logo_url'] ?? null)
                            <img src="{{ $company['logo_url'] }}" alt="{{ $company['name'] }}" class="company-logo">
                        @else
                            <div class="company-name">{{ $company['name'] }}</div>
                        @endif
                        <div class="company-details">
                            {{ $company['email'] }}
                            @if($company['address'] ?? null)
                                <br>{{ $company['address'] }}
                            @endif
                            @if($company['phone'] ?? null)
                                <br>{{ $company['phone'] }}
                            @endif
                        </div>
                    </td>
                    <td class="invoice-title-cell">
                        <div class="invoice-title">INVOICE</div>
                        <div class="invoice-number">#{{ $invoice->number }}</div>
                        <div class="status-badge status-{{ $invoice->status }}">
                            {{ ucfirst($invoice->status) }}
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <div class="divider"></div>

        {{-- DATES & AMOUNT --}}
        <div class="info-section">
            <table class="info-table">
                <tr>
                    <td style="width: 25%;">
                        <div class="info-label">Issue Date</div>
                        <div class="info-value">{{ $invoice->issue_date->format('M j, Y') }}</div>
                    </td>
                    <td style="width: 25%;">
                        <div class="info-label">Due Date</div>
                        <div class="info-value">{{ $invoice->due_date->format('M j, Y') }}</div>
                    </td>
                    @if($invoice->payment_terms)
                    <td style="width: 25%;">
                        <div class="info-label">Terms</div>
                        <div class="info-value">{{ $invoice->payment_terms }}</div>
                    </td>
                    @endif
                    <td style="width: 25%;">
                        <div class="info-label">Amount Due</div>
                        <div class="info-value-large">${{ number_format($invoice->amount_due, 2) }}</div>
                    </td>
                </tr>
            </table>
        </div>

        {{-- BILL TO / FROM --}}
        <table class="addresses-table">
            <tr>
                <td>
                    <div class="address-block">
                        <div class="address-title">Bill To</div>
                        <div class="address-name">{{ $client->name }}</div>
                        @if($client->billing_email)
                            <div class="address-detail">{{ $client->billing_email }}</div>
                        @endif
                        @if($client->billing_address)
                            <div class="address-detail">{!! nl2br(e($client->billing_address)) !!}</div>
                        @endif
                    </div>
                </td>
                @if($invoice->project)
                <td>
                    <div class="address-block">
                        <div class="address-title">Project</div>
                        <div class="address-name">{{ $invoice->project->name }}</div>
                    </div>
                </td>
                @endif
            </tr>
        </table>

        {{-- SUBJECT --}}
        @if($invoice->subject)
            <div class="subject-line">
                <strong>Re:</strong> {{ $invoice->subject }}
            </div>
        @endif

        {{-- LINE ITEMS --}}
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 50%;">Description</th>
                    <th class="align-right" style="width: 15%;">Qty</th>
                    <th class="align-right" style="width: 15%;">Rate</th>
                    <th class="align-right" style="width: 20%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse($lines as $line)
                    <tr class="{{ $line->type === 'discount' ? 'discount-row' : '' }}">
                        <td>
                            <div class="item-description">{{ $line->description }}</div>
                            @if($line->details)
                                <div class="item-details">{{ $line->details }}</div>
                            @endif
                        </td>
                        <td class="align-right item-amount">
                            @if($line->quantity != 1 || $line->unit)
                                {{ number_format($line->quantity, $line->quantity == floor($line->quantity) ? 0 : 2) }}{{ $line->unit ? ' '.$line->unit : '' }}
                            @endif
                        </td>
                        <td class="align-right item-amount">
                            @if($line->type !== 'discount' && $line->unit_price > 0)
                                ${{ number_format($line->unit_price, 2) }}
                            @endif
                        </td>
                        <td class="align-right item-amount">
                            @if($line->amount < 0)-@endif${{ number_format(abs($line->amount), 2) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" style="text-align: center; color: #999; padding: 32px;">
                            No line items
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{-- TOTALS --}}
        <table class="totals-section">
            <tr>
                <td class="totals-spacer"></td>
                <td class="totals-content">
                    <table class="totals-table">
                        <tr>
                            <td class="label">Subtotal</td>
                            <td class="value">${{ number_format($invoice->subtotal, 2) }}</td>
                        </tr>
                        @if(($invoice->discount_amount ?? 0) > 0)
                        <tr>
                            <td class="label">Discount</td>
                            <td class="value" style="color: #059669;">-${{ number_format($invoice->discount_amount, 2) }}</td>
                        </tr>
                        @endif
                        @if($invoice->tax_rate > 0)
                        <tr>
                            <td class="label">Tax ({{ number_format($invoice->tax_rate, 1) }}%)</td>
                            <td class="value">${{ number_format($invoice->tax_amount, 2) }}</td>
                        </tr>
                        @endif
                        @if($invoice->amount_paid > 0)
                        <tr>
                            <td class="label">Paid</td>
                            <td class="value" style="color: #059669;">-${{ number_format($invoice->amount_paid, 2) }}</td>
                        </tr>
                        @endif
                        <tr class="total-row">
                            <td class="label">Amount Due</td>
                            <td class="value">${{ number_format($invoice->amount_due, 2) }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        {{-- STATUS BANNERS --}}
        @if($invoice->isPaid())
            <div class="paid-banner">
                <div class="paid-banner-title">PAID IN FULL</div>
                <div class="paid-banner-date">Payment received {{ $invoice->paid_at->format('M j, Y') }}</div>
            </div>
        @elseif($invoice->amount_due > 0)
            @if($isPdf ?? false)
            <div class="amount-due-box">
                <div class="amount-due-label">Amount Due</div>
                <div class="amount-due-amount">${{ number_format($invoice->amount_due, 2) }}</div>
                <div class="amount-due-date">Due by {{ $invoice->due_date->format('F j, Y') }}</div>
            </div>
            @endif

            {{-- PAYMENT OPTIONS --}}
            <div class="payment-section">
                <div class="payment-title">Payment Options</div>

                <table class="payment-options">
                    <tr>
                        @if($paymentUrl ?? null)
                        <td>
                            <div class="payment-box highlight">
                                <div class="payment-box-title">Pay Online</div>
                                <div class="payment-box-desc">Pay securely with PayPal or credit card</div>
                                <a href="{{ $paymentUrl }}" class="pay-button" @if(!($isPdf ?? false))target="_blank"@endif>
                                    Pay ${{ number_format($invoice->amount_due, 2) }}
                                </a>
                            </div>
                        </td>
                        @endif
                        @if(config('app.bank_routing_number'))
                        <td>
                            <div class="payment-box">
                                <div class="payment-box-title">Bank Transfer (ACH)</div>
                                <table class="bank-table">
                                    <tr>
                                        <td class="bank-label">Bank</td>
                                        <td class="bank-value">{{ config('app.bank_name') }}</td>
                                    </tr>
                                    <tr>
                                        <td class="bank-label">Account</td>
                                        <td class="bank-value">{{ config('app.bank_account_name') }}</td>
                                    </tr>
                                    <tr>
                                        <td class="bank-label">Routing</td>
                                        <td class="bank-value">{{ config('app.bank_routing_number') }}</td>
                                    </tr>
                                    <tr>
                                        <td class="bank-label">Account #</td>
                                        <td class="bank-value">{{ config('app.bank_account_number') }}</td>
                                    </tr>
                                </table>
                                <div class="bank-reference">
                                    Include reference: <strong>#{{ $invoice->number }}</strong>
                                </div>
                            </div>
                        </td>
                        @elseif(!($paymentUrl ?? null))
                        <td>
                            <div class="payment-box">
                                <div class="payment-box-title">Contact Us</div>
                                <div class="payment-box-desc">
                                    Please contact us at {{ $company['email'] }} for payment arrangements.
                                </div>
                            </div>
                        </td>
                        @endif
                    </tr>
                </table>
            </div>
        @endif

        {{-- NOTES --}}
        @if($invoice->notes)
            <div class="notes-section">
                <div class="notes-label">Notes</div>
                <div class="notes-text">{!! nl2br(e($invoice->notes)) !!}</div>
            </div>
        @endif

        {{-- FOOTER --}}
        <div class="footer">
            <div class="footer-thanks">Thank you for your business!</div>
            <div class="footer-contact">
                Questions? Email <a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a>
            </div>
            @if($branding['footer'] ?? null)
                <div class="footer-brand">{!! nl2br(e($branding['footer'])) !!}</div>
            @endif
        </div>

        {{-- DOWNLOAD LINK (online only) --}}
        @if(!($isPdf ?? false))
            <div class="download-section">
                <a href="{{ route('invoices.public.pdf', ['invoice' => $invoice->number, 'token' => $invoice->public_token]) }}"
                   class="download-link">
                    Download PDF
                </a>
            </div>
        @endif
    </div>
</body>
</html>
