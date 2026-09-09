<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        @page { margin: 40px 50px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #333; line-height: 1.4; }
        .primary { color: {{ $branding['primary_color'] }}; }
        .primary-bg { background-color: {{ $branding['primary_color'] }}; }
        .muted { color: #666; }
        .small { font-size: 10px; }
        .bold { font-weight: bold; }
        .right { text-align: right; }
        .center { text-align: center; }
        .mono { font-family: Courier, monospace; }

        h1 { font-size: 28px; margin: 0 0 5px 0; color: {{ $branding['primary_color'] }}; }

        .header-table { width: 100%; margin-bottom: 30px; }
        .header-table td { vertical-align: top; }

        .badge { background: {{ $branding['primary_color'] }}; color: #fff; padding: 15px 20px; display: inline-block; border-radius: 8px; }
        .badge-label { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8; }
        .badge-number { font-size: 22px; font-weight: bold; margin-top: 3px; }

        .meta-table { width: 100%; margin-bottom: 25px; }
        .meta-table td { vertical-align: top; padding-right: 20px; }
        .meta-table td:last-child { padding-right: 0; }

        .label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #999; margin-bottom: 5px; font-weight: bold; }
        .info-box { background: #f8f9fa; border: 1px solid #e9ecef; padding: 12px; border-radius: 5px; }
        .client-name { font-size: 15px; font-weight: bold; }
        .client-detail { color: #666; font-size: 11px; margin-top: 3px; }

        .detail-item { margin-bottom: 8px; }
        .detail-item:last-child { margin-bottom: 0; }
        .detail-value { font-weight: 600; }
        .overdue { color: #dc3545; }

        .subject-box { background: #e7f3ff; border-left: 4px solid {{ $branding['primary_color'] }}; padding: 10px 15px; margin-bottom: 20px; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .items-table th { background: {{ $branding['primary_color'] }}; color: #fff; padding: 10px 12px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
        .items-table th.right { text-align: right; }
        .items-table td { padding: 12px; border-bottom: 1px solid #e9ecef; vertical-align: top; }
        .items-table td.right { text-align: right; }
        .items-table .empty { text-align: center; color: #999; padding: 30px; }
        .line-desc { font-weight: 500; }
        .line-detail { font-size: 10px; color: #999; margin-top: 3px; }
        .discount { color: #28a745; }

        .totals-row { width: 100%; }
        .totals-row td { padding: 5px 0; }
        .totals-row .label-col { text-align: right; padding-right: 15px; color: #666; width: 80%; }
        .totals-row .value-col { text-align: right; font-family: Courier, monospace; width: 20%; }
        .totals-row.grand td { border-top: 2px solid {{ $branding['primary_color'] }}; padding-top: 10px; font-weight: bold; }
        .totals-row.grand .label-col { color: #333; }
        .totals-row.grand .value-col { font-size: 16px; color: {{ $branding['primary_color'] }}; }

        .status-box { padding: 20px; border-radius: 8px; text-align: center; margin: 25px 0; }
        .status-box.unpaid { background: {{ $branding['primary_color'] }}; color: #fff; }
        .status-box.paid { background: #28a745; color: #fff; }
        .status-label { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8; }
        .status-amount { font-size: 28px; font-weight: bold; font-family: Courier, monospace; margin-top: 5px; }
        .status-due { font-size: 11px; opacity: 0.7; margin-top: 5px; }

        .payment-table { width: 100%; margin-bottom: 20px; }
        .payment-table td { vertical-align: top; width: 50%; padding: 5px; }
        .payment-box { border: 1px solid #e9ecef; border-radius: 6px; padding: 15px; height: 100%; }
        .payment-box.paypal { background: #e7f3ff; border-color: #b6d4fe; }
        .payment-title { font-weight: bold; margin-bottom: 5px; }
        .payment-desc { font-size: 11px; color: #666; }
        .payment-btn { display: inline-block; background: #0070ba; color: #fff; padding: 8px 16px; border-radius: 4px; font-size: 11px; font-weight: bold; text-decoration: none; margin-top: 10px; }

        .notes-box { background: #fff8e6; border: 1px solid #ffe69c; padding: 12px 15px; border-radius: 5px; margin-bottom: 20px; }
        .notes-label { font-size: 9px; text-transform: uppercase; color: #997a00; font-weight: bold; margin-bottom: 5px; }
        .notes-text { font-size: 11px; color: #333; }

        .footer { border-top: 1px solid #e9ecef; padding-top: 15px; text-align: center; margin-top: 20px; }
        .footer-thanks { font-weight: 500; margin-bottom: 5px; }
        .footer-contact { font-size: 11px; color: #666; }
        .footer-contact a { color: {{ $branding['primary_color'] }}; text-decoration: none; }
        .footer-brand { margin-top: 10px; font-size: 11px; color: {{ $branding['primary_color'] }}; font-style: italic; }
    </style>
</head>
<body>
    {{-- HEADER --}}
    <table class="header-table">
        <tr>
            <td style="width: 60%;">
                <h1>{{ $company['name'] }}</h1>
                <div class="muted small">
                    @if($company['address']){{ $company['address'] }}<br>@endif
                    {{ $company['email'] }}
                    @if($company['phone'])<br>{{ $company['phone'] }}@endif
                </div>
            </td>
            <td style="width: 40%; text-align: right;">
                <div class="badge">
                    <div class="badge-label">Invoice</div>
                    <div class="badge-number">{{ $invoice->number }}</div>
                </div>
            </td>
        </tr>
    </table>

    {{-- META INFO --}}
    <table class="meta-table">
        <tr>
            <td style="width: 55%;">
                <div class="label">Bill To</div>
                <div class="info-box">
                    <div class="client-name">{{ $client->name }}</div>
                    @if($client->billing_email)
                        <div class="client-detail">{{ $client->billing_email }}</div>
                    @endif
                    @if($client->billing_address)
                        <div class="client-detail">{{ $client->billing_address }}</div>
                    @endif
                </div>
            </td>
            <td style="width: 45%;">
                <div class="label">Details</div>
                <div class="info-box">
                    <div class="detail-item">
                        <div class="small muted">Issue Date</div>
                        <div class="detail-value">{{ $invoice->issue_date->format('M j, Y') }}</div>
                    </div>
                    <div class="detail-item">
                        <div class="small muted">Due Date</div>
                        <div class="detail-value {{ $invoice->due_date->isPast() && $invoice->amount_due > 0 ? 'overdue' : '' }}">
                            {{ $invoice->due_date->format('M j, Y') }}
                        </div>
                    </div>
                    @if($invoice->project)
                    <div class="detail-item">
                        <div class="small muted">Project</div>
                        <div class="detail-value">{{ $invoice->project->name }}</div>
                    </div>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    {{-- SUBJECT --}}
    @if($invoice->subject)
        <div class="subject-box">
            <strong>{{ $invoice->subject }}</strong>
        </div>
    @endif

    {{-- LINE ITEMS --}}
    <table class="items-table">
        <thead>
            <tr>
                <th>Description</th>
                <th class="right" style="width: 60px;">Qty</th>
                <th class="right" style="width: 70px;">Rate</th>
                <th class="right" style="width: 85px;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lines as $line)
                <tr>
                    <td>
                        <div class="line-desc {{ $line->type === 'discount' ? 'discount' : '' }}">{{ $line->description }}</div>
                        @if($line->details)
                            <div class="line-detail">{{ $line->details }}</div>
                        @endif
                    </td>
                    <td class="right mono">
                        @if($line->quantity != 1 || $line->unit)
                            {{ number_format($line->quantity, $line->quantity == floor($line->quantity) ? 0 : 2) }}{{ $line->unit ? ' '.$line->unit : '' }}
                        @endif
                    </td>
                    <td class="right mono">
                        @if($line->type !== 'discount' && $line->unit_price > 0)
                            ${{ number_format($line->unit_price, 2) }}
                        @endif
                    </td>
                    <td class="right mono bold {{ $line->type === 'discount' ? 'discount' : '' }}">
                        @if($line->amount < 0)-@endif${{ number_format(abs($line->amount), 2) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="empty">No line items</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- TOTALS --}}
    <table class="totals-row">
        <tr>
            <td class="label-col">Subtotal</td>
            <td class="value-col">${{ number_format($invoice->subtotal, 2) }}</td>
        </tr>
        @if($invoice->discount_amount > 0)
        <tr>
            <td class="label-col">Discount</td>
            <td class="value-col discount">-${{ number_format($invoice->discount_amount, 2) }}</td>
        </tr>
        @endif
        @if($invoice->tax_rate > 0)
        <tr>
            <td class="label-col">Tax ({{ number_format($invoice->tax_rate, 1) }}%)</td>
            <td class="value-col">${{ number_format($invoice->tax_amount, 2) }}</td>
        </tr>
        @endif
        @if($invoice->amount_paid > 0)
        <tr>
            <td class="label-col">Paid</td>
            <td class="value-col discount">-${{ number_format($invoice->amount_paid, 2) }}</td>
        </tr>
        @endif
        <tr class="grand">
            <td class="label-col">Amount Due</td>
            <td class="value-col">${{ number_format($invoice->amount_due, 2) }}</td>
        </tr>
    </table>

    {{-- STATUS BANNER --}}
    @if($invoice->amount_due > 0)
        <div class="status-box unpaid">
            <div class="status-label">Amount Due</div>
            <div class="status-amount">${{ number_format($invoice->amount_due, 2) }}</div>
            <div class="status-due">Due {{ $invoice->due_date->format('M j, Y') }}</div>
        </div>

        {{-- PAYMENT OPTIONS --}}
        <div class="label">Payment Options</div>
        <table class="payment-table">
            <tr>
                @if($paymentUrl ?? null)
                <td>
                    <div class="payment-box paypal">
                        <div class="payment-title">Pay with PayPal</div>
                        <div class="payment-desc">Credit card or PayPal balance</div>
                        <a href="{{ $paymentUrl }}" class="payment-btn">Pay Now</a>
                    </div>
                </td>
                @endif
                <td>
                    <div class="payment-box">
                        <div class="payment-title">Bank Transfer (ACH)</div>
                        <div class="payment-desc">Contact us for bank details.<br>Reference: {{ $invoice->number }}</div>
                    </div>
                </td>
            </tr>
        </table>
    @else
        <div class="status-box paid">
            <div class="status-label">Status</div>
            <div class="status-amount">Paid in Full</div>
        </div>
    @endif

    {{-- NOTES --}}
    @if($invoice->notes)
        <div class="notes-box">
            <div class="notes-label">Notes</div>
            <div class="notes-text">{!! nl2br(e($invoice->notes)) !!}</div>
        </div>
    @endif

    {{-- FOOTER --}}
    <div class="footer">
        <div class="footer-thanks">Thank you for your business!</div>
        <div class="footer-contact">Questions? Contact us at <a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a></div>
        @if($branding['footer'])
            <div class="footer-brand">{!! nl2br(e($branding['footer'])) !!}</div>
        @endif
    </div>
</body>
</html>
