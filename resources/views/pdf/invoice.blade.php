{{--
    Beautiful Invoice PDF Template using Tailwind CSS

    Usage:
    TailwindPdf::view('pdf.invoice', [
        'invoice' => $invoice,
        'company' => [...],
        'branding' => [...],
    ])->save('invoices/123.pdf');
--}}

@php
    $primaryColor = $branding['primary_color'] ?? '#2563eb';
    $isPaid = $invoice->isPaid();
    $isOverdue = !$isPaid && $invoice->due_date->isPast();
@endphp

<div class="w-[8.5in] min-h-[11in] bg-white mx-auto">
    {{-- Page 1 --}}
    <div class="p-12">
        {{-- Header --}}
        <div class="flex justify-between items-start mb-8">
            <div>
                @if($company['logo_url'] ?? null)
                    <img src="{{ $company['logo_url'] }}" alt="{{ $company['name'] }}" class="h-12 max-w-48 object-contain mb-2">
                @else
                    <div class="text-2xl font-bold" style="color: {{ $primaryColor }}">{{ $company['name'] }}</div>
                @endif
                <div class="text-sm text-gray-600 mt-1">
                    {{ $company['email'] }}
                    @if($company['address'] ?? null)
                        <br>{{ $company['address'] }}
                    @endif
                    @if($company['phone'] ?? null)
                        <br>{{ $company['phone'] }}
                    @endif
                </div>
            </div>
            <div class="text-right">
                <div class="text-4xl font-bold text-gray-800 tracking-tight">INVOICE</div>
                <div class="text-gray-600 mt-1">#{{ $invoice->number }}</div>
                @if($isPaid)
                    <div class="inline-block px-3 py-1 text-xs font-bold uppercase tracking-wide bg-emerald-600 text-white mt-2">
                        Paid
                    </div>
                @elseif($isOverdue)
                    <div class="inline-block px-3 py-1 text-xs font-bold uppercase tracking-wide bg-red-600 text-white mt-2">
                        Overdue
                    </div>
                @else
                    <div class="inline-block px-3 py-1 text-xs font-bold uppercase tracking-wide text-white mt-2" style="background-color: {{ $primaryColor }}">
                        {{ ucfirst($invoice->status) }}
                    </div>
                @endif
            </div>
        </div>

        {{-- Accent Line --}}
        <div class="h-0.5 mb-6" style="background-color: {{ $primaryColor }}"></div>

        {{-- Dates & Amount Row --}}
        <div class="grid grid-cols-4 gap-6 mb-8">
            <div>
                <div class="text-xs uppercase tracking-wide text-gray-500 font-semibold mb-1">Issue Date</div>
                <div class="text-base font-medium">{{ $invoice->issue_date->format('M j, Y') }}</div>
            </div>
            <div>
                <div class="text-xs uppercase tracking-wide text-gray-500 font-semibold mb-1">Due Date</div>
                <div class="text-base font-medium">{{ $invoice->due_date->format('M j, Y') }}</div>
            </div>
            @if($invoice->payment_terms)
            <div>
                <div class="text-xs uppercase tracking-wide text-gray-500 font-semibold mb-1">Terms</div>
                <div class="text-base font-medium">{{ $invoice->payment_terms }}</div>
            </div>
            @endif
            <div>
                <div class="text-xs uppercase tracking-wide text-gray-500 font-semibold mb-1">Amount Due</div>
                <div class="text-xl font-bold" style="color: {{ $primaryColor }}">${{ number_format($invoice->amount_due, 2) }}</div>
            </div>
        </div>

        {{-- Bill To / Project --}}
        <div class="grid grid-cols-2 gap-8 mb-8">
            <div>
                <div class="text-xs uppercase tracking-wide text-gray-500 font-semibold mb-2 pb-1 border-b border-gray-200">Bill To</div>
                <div class="font-semibold text-gray-900">{{ $client->name }}</div>
                @if($client->billing_email)
                    <div class="text-sm text-gray-600">{{ $client->billing_email }}</div>
                @endif
                @if($client->billing_address)
                    <div class="text-sm text-gray-600 whitespace-pre-line">{{ $client->billing_address }}</div>
                @endif
            </div>
            @if($invoice->project)
            <div>
                <div class="text-xs uppercase tracking-wide text-gray-500 font-semibold mb-2 pb-1 border-b border-gray-200">Project</div>
                <div class="font-semibold text-gray-900">{{ $invoice->project->name }}</div>
            </div>
            @endif
        </div>

        {{-- Subject Line --}}
        @if($invoice->subject)
        <div class="border-l-4 bg-gray-50 px-4 py-3 mb-6" style="border-color: {{ $primaryColor }}">
            <span class="font-medium">Re:</span> {{ $invoice->subject }}
        </div>
        @endif

        {{-- Line Items Table --}}
        <table class="w-full mb-6">
            <thead>
                <tr class="border-b-2 border-gray-800">
                    <th class="text-left py-3 px-2 text-xs uppercase tracking-wide text-gray-600 font-semibold w-1/2">Description</th>
                    <th class="text-right py-3 px-2 text-xs uppercase tracking-wide text-gray-600 font-semibold w-[15%]">Qty</th>
                    <th class="text-right py-3 px-2 text-xs uppercase tracking-wide text-gray-600 font-semibold w-[15%]">Rate</th>
                    <th class="text-right py-3 px-2 text-xs uppercase tracking-wide text-gray-600 font-semibold w-[20%]">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse($lines as $line)
                <tr class="border-b border-gray-200 break-inside-avoid {{ $line->type === 'discount' ? 'text-emerald-600' : '' }}">
                    <td class="py-3 px-2">
                        <div class="font-medium text-gray-900">{{ $line->description }}</div>
                        @if($line->details)
                            <div class="text-sm text-gray-500 mt-0.5">{{ $line->details }}</div>
                        @endif
                    </td>
                    <td class="text-right py-3 px-2 font-mono">
                        @if($line->quantity != 1 || $line->unit)
                            {{ number_format($line->quantity, $line->quantity == floor($line->quantity) ? 0 : 2) }}{{ $line->unit ? ' '.$line->unit : '' }}
                        @endif
                    </td>
                    <td class="text-right py-3 px-2 font-mono">
                        @if($line->type !== 'discount' && $line->unit_price > 0)
                            ${{ number_format($line->unit_price, 2) }}
                        @endif
                    </td>
                    <td class="text-right py-3 px-2 font-mono font-medium">
                        @if($line->amount < 0)-@endif${{ number_format(abs($line->amount), 2) }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" class="py-8 text-center text-gray-400">No line items</td>
                </tr>
                @endforelse
            </tbody>
        </table>

        {{-- Totals --}}
        <div class="flex justify-end mb-8">
            <div class="w-64">
                <div class="flex justify-between py-2">
                    <span class="text-gray-600">Subtotal</span>
                    <span class="font-mono">${{ number_format($invoice->subtotal, 2) }}</span>
                </div>
                @if(($invoice->discount_amount ?? 0) > 0)
                <div class="flex justify-between py-2 text-emerald-600">
                    <span>Discount</span>
                    <span class="font-mono">-${{ number_format($invoice->discount_amount, 2) }}</span>
                </div>
                @endif
                @if($invoice->tax_rate > 0)
                <div class="flex justify-between py-2">
                    <span class="text-gray-600">Tax ({{ number_format($invoice->tax_rate, 1) }}%)</span>
                    <span class="font-mono">${{ number_format($invoice->tax_amount, 2) }}</span>
                </div>
                @endif
                @if($invoice->amount_paid > 0)
                <div class="flex justify-between py-2 text-emerald-600">
                    <span>Paid</span>
                    <span class="font-mono">-${{ number_format($invoice->amount_paid, 2) }}</span>
                </div>
                @endif
                <div class="flex justify-between py-3 border-t-2 border-gray-800 mt-2">
                    <span class="font-bold text-gray-800">Amount Due</span>
                    <span class="font-mono font-bold text-lg" style="color: {{ $primaryColor }}">${{ number_format($invoice->amount_due, 2) }}</span>
                </div>
            </div>
        </div>

        {{-- Paid Banner --}}
        @if($isPaid)
        <div class="bg-emerald-600 text-white px-6 py-5 text-center mb-8">
            <div class="text-lg font-bold">PAID IN FULL</div>
            <div class="text-sm opacity-90">Payment received {{ $invoice->paid_at->format('M j, Y') }}</div>
        </div>
        @else
        {{-- Amount Due Box --}}
        <div class="text-white px-6 py-5 text-center mb-8" style="background-color: {{ $primaryColor }}">
            <div class="text-xs uppercase tracking-wider opacity-90">Amount Due</div>
            <div class="text-3xl font-bold font-mono">${{ number_format($invoice->amount_due, 2) }}</div>
            <div class="text-sm opacity-90 mt-1">Due by {{ $invoice->due_date->format('F j, Y') }}</div>
        </div>

        {{-- Payment Options --}}
        @if($paymentUrl ?? null)
        <div class="border-t border-gray-200 pt-6 mb-8">
            <div class="font-bold text-gray-800 mb-4">Payment Options</div>
            <div class="grid grid-cols-2 gap-4">
                <div class="border border-blue-200 bg-blue-50 p-4">
                    <div class="font-bold text-gray-800 mb-2">Pay Online</div>
                    <div class="text-sm text-gray-600 mb-3">Pay securely with PayPal or credit card</div>
                    <a href="{{ $paymentUrl }}" class="inline-block bg-blue-600 text-white px-5 py-2 font-semibold text-sm">
                        Pay ${{ number_format($invoice->amount_due, 2) }}
                    </a>
                </div>
                @if(config('app.bank_routing_number'))
                <div class="border border-gray-200 p-4">
                    <div class="font-bold text-gray-800 mb-2">Bank Transfer (ACH)</div>
                    <div class="bg-gray-50 text-sm">
                        <div class="flex justify-between py-2 px-3 border-b border-gray-200">
                            <span class="text-gray-600">Bank</span>
                            <span class="font-mono">{{ config('app.bank_name') }}</span>
                        </div>
                        <div class="flex justify-between py-2 px-3 border-b border-gray-200">
                            <span class="text-gray-600">Routing</span>
                            <span class="font-mono">{{ config('app.bank_routing_number') }}</span>
                        </div>
                        <div class="flex justify-between py-2 px-3">
                            <span class="text-gray-600">Account</span>
                            <span class="font-mono">{{ config('app.bank_account_number') }}</span>
                        </div>
                    </div>
                    <div class="text-xs text-gray-500 mt-2">Reference: <strong>#{{ $invoice->number }}</strong></div>
                </div>
                @endif
            </div>
        </div>
        @endif
        @endif

        {{-- Notes --}}
        @if($invoice->notes)
        <div class="bg-amber-50 border border-amber-200 p-4 mb-6">
            <div class="text-xs uppercase tracking-wide text-amber-800 font-semibold mb-2">Notes</div>
            <div class="text-sm text-gray-700 whitespace-pre-line">{{ $invoice->notes }}</div>
        </div>
        @endif

        {{-- Footer --}}
        <div class="border-t border-gray-200 pt-4 text-center mt-auto">
            <div class="font-medium text-gray-800">Thank you for your business!</div>
            <div class="text-sm text-gray-600 mt-1">
                Questions? Email <a href="mailto:{{ $company['email'] }}" style="color: {{ $primaryColor }}">{{ $company['email'] }}</a>
            </div>
            @if($branding['footer'] ?? null)
                <div class="text-sm mt-2" style="color: {{ $primaryColor }}">{{ $branding['footer'] }}</div>
            @endif
        </div>
    </div>
</div>
